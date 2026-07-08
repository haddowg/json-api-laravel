<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataPersister\Eloquent;

use haddowg\JsonApi\Exception\ClientGeneratedIdAlreadyExists;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\BelongsToMany as PivotRelation;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApiLaravel\DataPersister\AbstractDataPersister;
use haddowg\JsonApiLaravel\DataPersister\SoftDeleteCapable;
use haddowg\JsonApiLaravel\DataPersister\TransactionalDataPersisterInterface;
use haddowg\JsonApiLaravel\Server\IdEncoderResolver;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * The reference Eloquent write persister (PLAN decision 2): the storage twin of the
 * {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider} and the
 * Laravel analogue of the bundle's `DoctrineDataPersister`. One instance serves every
 * Eloquent-mapped type — constructed with the SAME `type → model class-string` map the
 * provider uses — so it registers at the lowest priority (`-128`), letting an application
 * persister at the default priority shadow it for the types it serves.
 *
 * The handler owns the lifecycle; this persister only maps + commits:
 *  - {@see instantiate()} → `new Model` (Eloquent needs no constructor-less trick — a
 *    model's constructor takes only an optional attributes array);
 *  - {@see create()} / {@see update()} → `$model->save()` (the hydrator has mutated the
 *    loaded instance in place via core's `Accessor`, which hits `__set`/`setAttribute` so
 *    casts + mutators run and `$guarded`/`$fillable` are bypassed — the JSON:API field
 *    declaration is the sole allow-list);
 *  - {@see delete()} → `$model->delete()`.
 *
 * Each write runs inside a `transaction()` on the model's own connection: a single write
 * is atomic on its own, but the wrap makes the rollback-on-throw boundary explicit and —
 * because Laravel nests an inner transaction as a savepoint — it composes cleanly under an
 * outer batch transaction opened through {@see TransactionalDataPersisterInterface}. On
 * the single-op path (no batch open) it simply auto-commits, exactly like the Doctrine
 * reference.
 *
 * The segregated {@see TransactionalDataPersisterInterface} drives an Atomic Operations
 * batch (Phase 4): {@see beginTransaction()}/{@see commit()}/{@see rollback()} open, commit
 * and (guarded) roll back one transaction on the configured connection, inside which the
 * per-op writes above buffer as savepoints — non-durable until {@see commit()} yet
 * materialising each store-generated id immediately (`save()` sets it before its savepoint
 * releases), so a later batch operation can reference a just-created resource.
 */
final class EloquentDataPersister extends AbstractDataPersister implements TransactionalDataPersisterInterface, SoftDeleteCapable
{
    /**
     * @var array<string, class-string<Model>>
     */
    private readonly array $modelByType;

    /**
     * Whether the one-time batch-connection invariant check has passed, so it runs at most
     * once per persister rather than on every transaction open.
     */
    private bool $connectionsVerified = false;

    /**
     * The id-encoder resolver linkage wire ids decode through (ADR 0014) — injected, or
     * resolved lazily from the container on first use so the hand-constructed reference
     * wiring (`new EloquentDataPersister($modelByType)`) keeps working.
     */
    private ?IdEncoderResolver $idEncoders;

    private bool $idEncodersResolved;

    /**
     * @param array<string, class-string<Model>> $modelByType a `type → Eloquent model FQCN` map (the SAME map the read provider uses)
     * @param string|null                        $connection  the connection name the batch transaction controls run on (null = the default connection); the per-op writes use each model's own connection
     * @param IdEncoderResolver|null             $idEncoders  resolves a related type's id encoder (linkage decode, ADR 0014); null resolves it lazily from the container
     */
    public function __construct(array $modelByType, private readonly ?string $connection = null, ?IdEncoderResolver $idEncoders = null)
    {
        $this->modelByType = $modelByType;
        $this->idEncoders = $idEncoders;
        $this->idEncodersResolved = $idEncoders !== null;
    }

    public function supports(string $type): bool
    {
        return isset($this->modelByType[$type]);
    }

    public function instantiate(string $type): object
    {
        $class = $this->modelByType[$type] ?? throw new \LogicException(\sprintf(
            'The %s cannot instantiate the unmapped type "%s"; supports() gates this, so it is a wiring fault.',
            self::class,
            $type,
        ));

        return new $class();
    }

    public function create(string $type, object $entity): object
    {
        \assert($entity instanceof Model);

        // A client-supplied key that already exists is a `409`
        // CLIENT_GENERATED_ID_ALREADY_EXISTS (core defines the exception; the write layer
        // enforces it) rather than an unhandled duplicate-PK `QueryException` (`500`). A
        // server-generated create carries no key yet (`getKey()` is null), so it never
        // trips this — matching the in-memory witness's existence check.
        $key = $entity->getKey();
        if ($key !== null && $entity->newQueryWithoutScopes()->whereKey($key)->exists()) {
            throw new ClientGeneratedIdAlreadyExists(\is_scalar($key) ? (string) $key : '');
        }

        $entity->getConnection()->transaction(static function () use ($entity): void {
            $entity->save();
        });

        return $entity;
    }

    public function update(string $type, object $entity): object
    {
        \assert($entity instanceof Model);

        $entity->getConnection()->transaction(static function () use ($entity): void {
            $entity->save();
        });

        return $entity;
    }

    public function delete(string $type, object $entity): void
    {
        \assert($entity instanceof Model);

        $entity->getConnection()->transaction(static function () use ($entity): void {
            $entity->delete();
        });
    }

    /**
     * Restores a soft-deleted model — `$model->restore()` clears the tombstone — inside a
     * transaction on the model's own connection (the {@see SoftDeleteCapable} seam the
     * synthesized `restore` action commits through). The model uses Laravel's `SoftDeletes`
     * trait (the resource opted into soft deletes), so `restore()` is present.
     */
    public function restore(string $type, object $entity): object
    {
        \assert($entity instanceof Model && \method_exists($entity, 'restore'));

        $entity->getConnection()->transaction(static function () use ($entity): void {
            $entity->restore();
        });

        return $entity;
    }

    /**
     * Permanently removes a model — `$model->forceDelete()` bypasses the soft-delete tombstone —
     * inside a transaction on the model's own connection (the {@see SoftDeleteCapable} seam the
     * synthesized `force-delete` action commits through). The ordinary {@see delete()} above
     * stays a recoverable soft delete; this is the only permanent-removal path.
     */
    public function forceDelete(string $type, object $entity): void
    {
        \assert($entity instanceof Model);

        $entity->getConnection()->transaction(static function () use ($entity): void {
            $entity->forceDelete();
        });
    }

    /**
     * Applies a relationship-endpoint (or embedded) mutation to the parent, resolving the
     * linkage ids to related models and driving Eloquent's relation API by the relation's
     * storage mechanism — the reference analogue of the bundle's Doctrine
     * `mutateRelationship`. The Eloquent relation method is `column() ?? name()` (the SAME
     * resolution the read provider uses), and the returned relation object's class selects
     * the strategy:
     *  - a {@see BelongsTo} (incl. its polymorphic {@see MorphTo} subclass) is an owner-side
     *    FK on the parent row: {@see BelongsTo::associate()} a resolved member (setting the
     *    FK, and the morph `*_type` for a MorphTo) or {@see BelongsTo::dissociate()} on an
     *    empty (clearing) linkage, then `$parent->save()`;
     *  - a {@see HasOneOrMany} (incl. {@see \Illuminate\Database\Eloquent\Relations\MorphOne}/
     *    {@see \Illuminate\Database\Eloquent\Relations\MorphMany}) is an inverse FK on the
     *    related rows: a Replace re-parents the incoming members (sets their FK) and orphans
     *    the dropped ones (nulls their FK), an Add sets the FK on the incoming, a Remove nulls
     *    it on the incoming;
     *  - a {@see BelongsToMany} (incl. {@see \Illuminate\Database\Eloquent\Relations\MorphToMany})
     *    is a join table: Replace `sync()`s, Add `syncWithoutDetaching()`s, Remove `detach()`s
     *    the incoming ids (the writable-pivot payload is a further, pivot-machinery, feature —
     *    this arm attaches the bare join rows).
     *
     * `$flush === true` (a relationship endpoint) wraps the apply in a transaction on the
     * parent's connection; `$flush === false` (a whole-resource write embedding the
     * relationship) runs it inline under the outer create/update transaction, which owns the
     * single commit.
     */
    public function mutateRelationship(
        string $type,
        object $entity,
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        bool $flush = true,
    ): object {
        \assert($entity instanceof Model);

        $apply = function () use ($entity, $relation, $linkage, $mode, $flush): void {
            $method = $relation->column() ?? $relation->name();
            $eloquentRelation = $entity->{$method}();
            \assert($eloquentRelation instanceof Relation);

            match (true) {
                // MorphTo extends BelongsTo, MorphToMany extends BelongsToMany, and
                // MorphOne/MorphMany extend HasOneOrMany, so each instanceof also matches its
                // polymorphic subclass — the member-model resolution below reads each
                // linkage's own type, so a morph member resolves to the right class.
                $eloquentRelation instanceof BelongsTo => $this->mutateBelongsTo($entity, $eloquentRelation, $relation, $linkage, $flush),
                $eloquentRelation instanceof BelongsToMany => $this->mutateBelongsToMany($eloquentRelation, $relation, $linkage, $mode),
                $eloquentRelation instanceof HasOneOrMany => $this->mutateHasOneOrMany($eloquentRelation, $relation, $linkage, $mode),
                default => throw new \LogicException(\sprintf(
                    'The "%s" relation resolves to an unsupported Eloquent relation type %s for mutation.',
                    $relation->name(),
                    $eloquentRelation::class,
                )),
            };
        };

        if ($flush) {
            $entity->getConnection()->transaction($apply);
        } else {
            $apply();
        }

        return $entity;
    }

    public function beginTransaction(): void
    {
        $this->assertModelsShareTheBatchConnection();
        $this->connection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection()->commit();
    }

    public function rollback(): void
    {
        $connection = $this->connection();

        // Guarded, mirroring the Doctrine reference: a rollback is only issued when a
        // transaction is actually open, so a rollback after a failed begin (or a nested
        // failure that already unwound) is a safe no-op rather than a "no active
        // transaction" error.
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }

    /**
     * Owner-side to-one FK on the parent row (a {@see BelongsTo} or its polymorphic
     * {@see MorphTo}): associate a resolved member (setting the FK, and the morph `*_type`),
     * or dissociate on an empty (clearing) linkage, then persist the parent.
     *
     * When `$flush` is false the owner FK is set on the parent instance but the parent is
     * NOT saved — the association is embedded in a whole-resource write, so the subsequent
     * create()/update() owns the single commit (and a not-yet-persisted create target,
     * whose other NOT-NULL columns are still un-hydrated, is never inserted mid-association).
     *
     * @param BelongsTo<Model, Model> $eloquentRelation
     */
    private function mutateBelongsTo(Model $parent, BelongsTo $eloquentRelation, RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, bool $flush): void
    {
        $identifier = $linkage instanceof ToOneRelationship ? $linkage->resourceIdentifier : null;

        if ($identifier === null || $identifier->id === null) {
            $eloquentRelation->dissociate();
        } else {
            $eloquentRelation->associate($this->referenceFor($relation, $identifier));
        }

        if ($flush) {
            $parent->save();
        }
    }

    /**
     * Inverse FK on the related rows (a {@see HasOneOrMany} — {@see \Illuminate\Database\Eloquent\Relations\HasOne}/
     * {@see \Illuminate\Database\Eloquent\Relations\HasMany} and their morph variants): a
     * Replace re-parents the incoming members and orphans the dropped ones (nulling their
     * FK), an Add sets the FK on the incoming, a Remove nulls it on the incoming.
     *
     * @param HasOneOrMany<Model, Model, mixed> $eloquentRelation
     */
    private function mutateHasOneOrMany(HasOneOrMany $eloquentRelation, RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, Mode $mode): void
    {
        $foreignKey = $eloquentRelation->getForeignKeyName();
        // A polymorphic inverse FK (MorphOne/MorphMany) also carries a `*_type` discriminator on
        // the related row; orphaning must null it alongside the FK, or the orphaned row keeps a
        // dangling type pointing at the former parent's class.
        $morphType = $eloquentRelation instanceof MorphOneOrMany ? $eloquentRelation->getMorphType() : null;
        $incoming = $this->incomingModels($relation, $linkage);

        // A to-one inverse FK (HasOne): orphan the current holder, then adopt the incoming.
        if ($linkage instanceof ToOneRelationship) {
            $current = $eloquentRelation->getResults();
            if ($current instanceof Model) {
                $this->orphan($current, $foreignKey, $morphType);
            }
            foreach ($incoming as $member) {
                $eloquentRelation->save($member);
            }

            return;
        }

        if ($mode === Mode::Remove) {
            foreach ($incoming as $member) {
                $this->orphan($member, $foreignKey, $morphType);
            }

            return;
        }

        // A full Replace orphans the current members not in the incoming set (an FK-move):
        // null their FK before the incoming are re-parented below.
        if ($mode === Mode::Replace) {
            $keep = [];
            foreach ($incoming as $member) {
                $keep[$this->key($member)] = true;
            }
            foreach ($eloquentRelation->get() as $current) {
                \assert($current instanceof Model);
                if (!isset($keep[$this->key($current)])) {
                    $this->orphan($current, $foreignKey, $morphType);
                }
            }
        }

        foreach ($incoming as $member) {
            $eloquentRelation->save($member);
        }
    }

    /**
     * Join-table to-many (a {@see BelongsToMany} or its polymorphic {@see \Illuminate\Database\Eloquent\Relations\MorphToMany}):
     * Replace `sync()`s the incoming members (inserting new join rows, dropping absent ones),
     * Add `syncWithoutDetaching()`s (idempotent), Remove `detach()`es.
     *
     * For a relation with **declared writable pivot fields** each member carries its pivot
     * `meta.pivot`, so the payload is a `relatedId => [pivotColumn => value]` map: `sync()`/
     * `syncWithoutDetaching()` insert a new join row with those columns or update an existing
     * row's columns in place (Eloquent's `updateExistingPivot` writes only the supplied
     * columns, so a partial reorder preserves the rest). A member's writable set is resolved
     * per its context — an already-attached member is an update, a genuinely-new one a create —
     * matching the merge-before-validate pass; a readOnly pivot field is never written from
     * meta (it takes its server-owned default on insert). A bare join (no pivot fields) or a
     * Remove uses the id-only form.
     *
     * @param BelongsToMany<Model, Model> $eloquentRelation
     */
    private function mutateBelongsToMany(BelongsToMany $eloquentRelation, RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, Mode $mode): void
    {
        if (!$linkage instanceof ToManyRelationship) {
            return; // a to-one linkage to a to-many relation is a cardinality error, caught upstream
        }

        // Linkage ids arrive as WIRE ids; decode each to its storage key (by the member's
        // own related type) before it becomes a join-row key (ADR 0014) — an undecodable
        // token raises the same 404 a missing target would. A type with no encoder decodes
        // to itself, so the path is byte-identical to today for the common case.
        $ids = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            if ($identifier->id === null) {
                continue;
            }
            /** @var mixed $storageId */
            $storageId = $this->decodeLinkageId($this->relatedType($relation, $identifier), $identifier->id);
            $ids[] = $storageId;
        }

        // A Remove carries no pivot meta, and a bare join has no pivot columns — the id-only
        // sync/attach/detach form covers both.
        if ($mode === Mode::Remove || !$relation instanceof PivotRelation || $relation->pivotFields() === []) {
            match ($mode) {
                Mode::Replace => $eloquentRelation->sync($ids),
                Mode::Add => $eloquentRelation->syncWithoutDetaching($ids),
                Mode::Remove => $eloquentRelation->detach($ids),
            };

            return;
        }

        // Which members are already attached — so each resolves its writable pivot set in the
        // right (create vs update) context, exactly as the validator's merge-before-validate
        // pass determined it. `allRelatedIds()` reads the join rows, so the set holds STORAGE
        // keys — the decoded linkage ids compare against it directly.
        $attached = [];
        foreach ($eloquentRelation->allRelatedIds() as $existing) {
            $attached[\is_scalar($existing) ? (string) $existing : ''] = true;
        }

        $payload = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            if ($identifier->id === null) {
                continue;
            }
            /** @var mixed $storageId */
            $storageId = $this->decodeLinkageId($this->relatedType($relation, $identifier), $identifier->id);
            if (!\is_scalar($storageId)) {
                continue;
            }
            $creating = !isset($attached[(string) $storageId]);
            $payload[(string) $storageId] = $this->pivotAttributes($relation, $identifier->meta, $creating);
        }

        if ($mode === Mode::Add) {
            $eloquentRelation->syncWithoutDetaching($payload);

            return;
        }

        $eloquentRelation->sync($payload);
    }

    /**
     * The pivot-column payload for one linkage member: each writable pivot field present in
     * the member's `meta.pivot` mapped to its `column ?? name`, its wire value coerced through
     * the field's own {@see FieldInterface::castWireValue()} (an `Integer` → int, a `DateTime`
     * → `\DateTimeImmutable`) — the type's value cast alone, request-independent. A field
     * absent from meta is left out (its stored value is preserved on update, its server default
     * taken on insert); a readOnly field is never in the writable set, so never written.
     *
     * @param array<string, mixed> $meta the linkage member's full meta (pivot values nest under `pivot`)
     *
     * @return array<string, mixed> a `pivotColumn => value` map (possibly empty)
     */
    private function pivotAttributes(PivotRelation $relation, array $meta, bool $creating): array
    {
        $pivot = $meta['pivot'] ?? [];
        if (!\is_array($pivot)) {
            return [];
        }

        $attributes = [];
        foreach ($relation->writablePivotFields($creating) as $field) {
            if (!\array_key_exists($field->name(), $pivot)) {
                continue;
            }
            $column = $field->column() ?? $field->name();
            /** @var mixed $raw */
            $raw = $pivot[$field->name()];
            $attributes[$column] = $field->castWireValue($raw);
        }

        return $attributes;
    }

    /**
     * The loaded related models for a linkage, resolved through the `type → model` map by
     * each identifier's OWN type (so a polymorphic member resolves to the right class),
     * skipping an id that resolves to no persisted row. Used by the inverse-FK arms, which
     * re-parent existing related rows.
     *
     * @return list<Model>
     */
    private function incomingModels(RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage): array
    {
        $identifiers = $linkage instanceof ToOneRelationship
            ? ($linkage->resourceIdentifier !== null ? [$linkage->resourceIdentifier] : [])
            : $linkage->resourceIdentifiers;

        $models = [];
        foreach ($identifiers as $identifier) {
            $model = $this->findRelated($relation, $identifier);
            if ($model instanceof Model) {
                $models[] = $model;
            }
        }

        return $models;
    }

    /**
     * The persisted related model for an identifier, or `null` when the type is unmapped or
     * no row matches the id. The related type is the identifier's own `type` (polymorphic
     * safe), falling back to the relation's single declared related type. The identifier's
     * `id` is a WIRE id, decoded to its storage key before the find when the related type
     * declares an id encoder (ADR 0014).
     */
    private function findRelated(RelationInterface $relation, ResourceIdentifier $identifier): ?Model
    {
        $relatedType = $this->relatedType($relation, $identifier);
        $class = $this->modelByType[$relatedType] ?? null;
        if ($class === null || $identifier->id === null) {
            return null;
        }

        $found = $class::query()->find($this->decodeLinkageId($relatedType, $identifier->id));

        return $found instanceof Model ? $found : null;
    }

    /**
     * A model instance for a to-one {@see BelongsTo}/{@see MorphTo} target to associate:
     * the persisted row when it exists, else a keyed blank of the mapped class so
     * {@see BelongsTo::associate()} can still set the FK (and the morph `*_type`) — any FK
     * constraint surfaces the referential error on save.
     */
    private function referenceFor(RelationInterface $relation, ResourceIdentifier $identifier): Model
    {
        $found = $this->findRelated($relation, $identifier);
        if ($found instanceof Model) {
            return $found;
        }

        $class = $this->modelByType[$this->relatedType($relation, $identifier)] ?? throw new \LogicException(\sprintf(
            'The %s cannot resolve the related model for the unmapped type "%s".',
            self::class,
            $this->relatedType($relation, $identifier),
        ));

        $blank = new $class();
        if ($identifier->id !== null) {
            // The FK holds the STORAGE key, so the wire id decodes first (ADR 0014) —
            // never a raw wire token keyed into an integer column.
            $blank->setAttribute(
                $blank->getKeyName(),
                $this->decodeLinkageId($this->relatedType($relation, $identifier), $identifier->id),
            );
        }

        return $blank;
    }

    /**
     * The related JSON:API type for a linkage member: its own `type` when present
     * (polymorphic safe), else the relation's single declared related type.
     */
    private function relatedType(RelationInterface $relation, ResourceIdentifier $identifier): string
    {
        return $identifier->type !== '' ? $identifier->type : ($relation->relatedTypes()[0] ?? '');
    }

    /**
     * Orphans an inverse-FK related model by nulling its foreign key — and, for a polymorphic
     * inverse FK ({@see MorphOneOrMany}), its `*_type` morph discriminator too — then persisting
     * it. Nulling only the FK on a morph relation would leave a dangling `*_type`.
     */
    private function orphan(Model $member, string $foreignKey, ?string $morphType = null): void
    {
        $member->setAttribute($foreignKey, null);
        if ($morphType !== null) {
            $member->setAttribute($morphType, null);
        }
        $member->save();
    }

    /**
     * Decodes a linkage member's wire id to its storage key when the RELATED type declares
     * an id encoder; otherwise (no encoder, wire == storage) returns it unchanged — the
     * write twin of the provider's route-`{id}` decode (ADR 0014, mirroring the bundle's
     * `DoctrineDataPersister::decodeLinkageId()`). An undecodable id can key no row, so it
     * surfaces as the same `404` a missing target raises — never a raw wire token written
     * into an integer FK/join column.
     */
    private function decodeLinkageId(string $relatedType, string $id): mixed
    {
        $encoder = $this->encoderFor($relatedType);
        if ($encoder === null) {
            return $id;
        }

        return $encoder->decode($id) ?? throw new ResourceNotFound();
    }

    /**
     * The id encoder declared by `$type`'s resource, or `null` when wire == storage (no
     * resource / no Id field / no encoder — today's behaviour, ADR 0014). The resolver is
     * injected, or resolved lazily from the container once so the hand-constructed
     * reference wiring keeps working; outside a container (a bare unit test) every type
     * resolves encoder-less.
     */
    private function encoderFor(string $type): ?IdEncoderInterface
    {
        if (!$this->idEncodersResolved) {
            $container = Container::getInstance();
            $this->idEncoders = $container->bound(IdEncoderResolver::class)
                ? $container->make(IdEncoderResolver::class)
                : null;
            $this->idEncodersResolved = true;
        }

        return $this->idEncoders?->encoderFor($type);
    }

    /**
     * A model's primary key as a string, for set membership on the incoming/current members.
     */
    private function key(Model $model): string
    {
        $key = $model->getKey();

        return \is_scalar($key) ? (string) $key : '';
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }

    /**
     * Fails loud (once) when a mapped model resolves to a different connection than the one
     * the batch transaction controls run on. The batch controls
     * ({@see beginTransaction()}/{@see commit()}/{@see rollback()}) open one transaction on
     * `$this->connection`, but each per-op write runs `$model->save()` on the MODEL's own
     * connection: if they differ, the write commits durably outside the batch and
     * {@see rollback()} could not undo it, silently breaking the transactional contract.
     * Enforcing equality up front turns that latent Phase-4 atomic-operations hazard into a
     * clear wiring error.
     */
    private function assertModelsShareTheBatchConnection(): void
    {
        if ($this->connectionsVerified) {
            return;
        }

        /** @var string $default */
        $default = config('database.default', 'default');
        $batch = $this->connection ?? $default;

        foreach ($this->modelByType as $type => $class) {
            $model = new $class();
            \assert($model instanceof Model);
            $modelConnection = $model->getConnectionName() ?? $default;
            if ($modelConnection !== $batch) {
                throw new \LogicException(\sprintf(
                    'The %s batch transaction runs on connection "%s" but the "%s" model (%s) resolves to '
                    . '"%s"; a per-op write would then commit outside the batch transaction and rollback() '
                    . 'could not undo it. Configure the persister with the models\' connection, or align the '
                    . 'models\' $connection.',
                    self::class,
                    $batch,
                    $type,
                    $class,
                    $modelConnection,
                ));
            }
        }

        $this->connectionsVerified = true;
    }
}
