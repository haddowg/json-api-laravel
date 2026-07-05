<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Operation\Operation;
use Illuminate\Database\Eloquent\Model;

/**
 * Asserts at `artisan optimize` (a deploy step) that every registered type is actually
 * **servable** — that the capabilities its exposed routes require are present — so a
 * configuration mistake fails the BUILD instead of a runtime 500 (or, worse, a silently
 * malformed response) on the first request (PLAN decision 11).
 *
 * Unlike the {@see \haddowg\JsonApiLaravel\OpenApi\DocumentWarmer} (optional, non-fatal),
 * this warmer is **mandatory**: {@see warm()} collects every problem and the
 * `jsonapi:optimize` command turns a non-empty result into a command FAILURE, so a bad
 * config fails the deploy step. It checks, per `(server, type)`:
 *
 *  - **A read operation needs a {@see DataProviderInterface}.** Collection/single fetch
 *    (and update/delete, which load-then-mutate) cannot run without a provider that
 *    supports the type.
 *  - **A write operation needs a {@see DataPersisterInterface}.** Declaring a hydrator is
 *    necessary but not sufficient — the persister commits it.
 *  - **An `AbstractResource` must declare exactly one {@see Id} field.** A missing Id
 *    otherwise serializes every object of the type with `id: ""` on every response, with
 *    no boot-time signal.
 *  - **A polymorphic relation's candidate serializers must discriminate by class.** A
 *    candidate that does not override {@see AbstractResource::getType()} returns
 *    `static::$type` unconditionally and so silently claims (and mis-serializes) members
 *    of its siblings' types — this is also the morph-map safety net (a mis-registered
 *    morph alias surfaces here).
 *  - **(Eloquent-backed types) every declared relation names a real model method.** A
 *    typo'd relation name would otherwise 500 (or return an empty relation) at runtime;
 *    this catches it at deploy. Gated on the type resolving to an
 *    {@see EloquentDataProvider} model, so a POPO / in-memory type is not false-flagged.
 *  - **(Eloquent-backed types) every sortable field / column-backed filter resolves to a
 *    real table column.** A `->sortable()` field (or a column-backed filter) whose column
 *    was renamed in a migration would otherwise pass `php artisan optimize` cleanly and
 *    then `QueryException`-500 on the first `?sort=`/`?filter=` request (PLAN decision 11).
 *    Columns are resolved against the model's table (accepting cast keys); a qualified
 *    (dotted) path targets another table and is not validated. Skipped when schema
 *    introspection is unavailable (no migrated DB at build time — a different concern that
 *    must not fail the deploy).
 *
 * Gating is on the per-type operation allow-list, so a relationship-only target (served
 * through its parent's provider) is not false-flagged.
 */
final class ServableResourceWarmer
{
    /**
     * @param list<string> $serverNames the declared server names (including `default`)
     */
    public function __construct(
        private readonly ServerRegistry $servers,
        private readonly Discovery $discovery,
        private readonly DataProviderRegistry $providers,
        private readonly DataPersisterRegistry $persisters,
        private readonly TypeMetadataResolver $types,
        private readonly array $serverNames,
    ) {}

    /**
     * Validates every registered type across every server, returning the list of
     * servability problems (empty when the whole surface is servable). The
     * `jsonapi:optimize` command fails the deploy when this is non-empty.
     *
     * @return list<string>
     */
    public function warm(): array
    {
        $problems = [];

        foreach ($this->serverNames as $serverName) {
            foreach ($this->discovery->resourcesFor($serverName) as $descriptor) {
                $type = $descriptor->type;
                if ($type === '') {
                    continue;
                }

                $this->guardServability($type, $descriptor->operations, $problems);
                $this->guardExactlyOneId($serverName, $type, $problems);
                $this->guardPolymorphicDiscrimination($serverName, $type, $problems);
                $this->guardEloquentRelationMethods($serverName, $type, $problems);
                $this->guardSortableFilterableColumns($serverName, $type, $problems);
            }
        }

        return $problems;
    }

    /**
     * @param list<string> $operations the type's exposed CRUD operation allow-list
     * @param list<string> $problems
     */
    private function guardServability(string $type, array $operations, array &$problems): void
    {
        $needsProvider = \array_intersect(
            [Operation::FetchCollection->value, Operation::FetchOne->value, Operation::Update->value, Operation::Delete->value],
            $operations,
        ) !== [];
        if ($needsProvider && !$this->providers->supportsType($type)) {
            $problems[] = \sprintf(
                'The JSON:API type "%s" exposes a read operation but no DataProvider supports it. '
                . 'Register a DataProviderInterface for it (e.g. the Eloquent provider\'s model map), '
                . 'or remove its read operations from the allow-list.',
                $type,
            );
        }

        $needsPersister = \array_intersect(
            [Operation::Create->value, Operation::Update->value, Operation::Delete->value],
            $operations,
        ) !== [];
        if ($needsPersister && !$this->persisters->supportsType($type)) {
            $problems[] = \sprintf(
                'The JSON:API type "%s" exposes a write operation but no DataPersister supports it. '
                . 'Register a DataPersisterInterface for it, or remove its write operations from the allow-list.',
                $type,
            );
        }
    }

    /**
     * @param list<string> $problems
     */
    private function guardExactlyOneId(string $serverName, string $type, array &$problems): void
    {
        $resource = $this->types->resourceFor($this->servers->get($serverName), $type);
        if (!$resource instanceof AbstractResource) {
            return;
        }

        $idFields = \array_filter(
            $resource->fields(),
            static fn(FieldInterface $field): bool => $field instanceof Id,
        );

        if (\count($idFields) !== 1) {
            $problems[] = \sprintf(
                'The JSON:API resource for type "%s" must declare exactly one Id field, found %d. '
                . 'Add Id::make() to its fields().',
                $type,
                \count($idFields),
            );
        }
    }

    /**
     * Asserts that every candidate serializer of a polymorphic relation declared on
     * `$type` discriminates members by class — i.e. an `AbstractResource` candidate
     * overrides {@see AbstractResource::getType()}. A candidate that does not override it
     * returns `static::$type` unconditionally and so silently claims (and mis-serializes)
     * members of its siblings' types.
     *
     * @param list<string> $problems
     */
    private function guardPolymorphicDiscrimination(string $serverName, string $type, array &$problems): void
    {
        $server = $this->servers->get($serverName);

        foreach ($this->types->relationsFor($server, $type) as $relation) {
            $relatedTypes = $relation->relatedTypes();
            if (\count($relatedTypes) < 2) {
                continue; // a monomorphic relation never compares getType()
            }

            foreach ($relatedTypes as $candidateType) {
                if (!$server->hasResourceFor($candidateType)) {
                    continue;
                }

                // core's resourceFor() returns an AbstractResource for a registered type;
                // getType() is the discriminator core's resolveSerializer() compares.
                $candidate = $server->resourceFor($candidateType);

                $declaringClass = (new \ReflectionMethod($candidate, 'getType'))->getDeclaringClass()->getName();
                if ($declaringClass === AbstractResource::class) {
                    $problems[] = \sprintf(
                        'The polymorphic relationship "%s" on type "%s" lists candidate type "%s", whose '
                        . 'resource (%s) does not override getType(): it returns its static $type for every '
                        . 'object, so it would silently claim and mis-serialize members of the relationship\'s '
                        . 'other types. Override getType() to discriminate the member by class (e.g. with instanceof).',
                        $relation->name(),
                        $type,
                        $candidateType,
                        $candidate::class,
                    );
                }
            }
        }
    }

    /**
     * For an Eloquent-backed type, asserts that every declared relation names a real
     * method on the model (the relation name is the Eloquent relation method). A typo'd
     * relation name would otherwise fail at runtime. Gated on the type resolving to an
     * {@see EloquentDataProvider} model, so a POPO / in-memory type is skipped, and
     * wrapped so an unexpected reflection failure never aborts the deploy for an
     * unrelated reason.
     *
     * @param list<string> $problems
     */
    private function guardEloquentRelationMethods(string $serverName, string $type, array &$problems): void
    {
        $modelClass = $this->eloquentModelClassFor($type);
        if ($modelClass === null) {
            return;
        }

        $server = $this->servers->get($serverName);

        foreach ($this->types->relationsFor($server, $type) as $relation) {
            $name = $relation->name();
            if (!\method_exists($modelClass, $name)) {
                $problems[] = \sprintf(
                    'The relationship "%s" on type "%s" has no matching method on its Eloquent model %s. '
                    . 'The relation name must be the model\'s relation method; add %s() to the model or rename the relation.',
                    $name,
                    $type,
                    $modelClass,
                    $name,
                );
            }
        }
    }

    /**
     * For an Eloquent-backed type, asserts that every sortable field and column-backed
     * filter resolves to a real column on the model's table (accepting cast keys). A
     * `->sortable()` field or a column-backed filter whose column was renamed in a
     * migration would otherwise `QueryException`-500 on the first `?sort=`/`?filter=`
     * request; this catches it at deploy.
     *
     * A qualified (dotted) column path targets another table and is not validated here; a
     * custom (computed / multi-column) sort carries no single column; a relationship / id /
     * presence filter carries no `column` — all are skipped. Schema introspection failures
     * (no migrated DB at build time) skip the type entirely, so a missing connection never
     * fails the deploy for the wrong reason.
     *
     * @param list<string> $problems
     */
    private function guardSortableFilterableColumns(string $serverName, string $type, array &$problems): void
    {
        $modelClass = $this->eloquentModelClassFor($type);
        if ($modelClass === null) {
            return;
        }

        $resource = $this->types->resourceFor($this->servers->get($serverName), $type);
        if (!$resource instanceof AbstractResource) {
            return;
        }

        try {
            $model = new $modelClass();
            $columns = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
            $casts = $model->getCasts();
        } catch (\Throwable) {
            return; // schema not introspectable at build time — a different concern
        }
        if ($columns === []) {
            return; // table absent / not introspectable — treat as unavailable
        }

        $known = static function (string $column) use ($columns, $casts): bool {
            // A qualified/related path targets another table — not validated here.
            if (\str_contains($column, '.')) {
                return true;
            }

            return \in_array($column, $columns, true) || \array_key_exists($column, $casts);
        };

        foreach ($resource->allSorts() as $sort) {
            if ($sort instanceof SortByField && !$known($sort->column)) {
                $problems[] = \sprintf(
                    'The sortable "%s" on type "%s" resolves to column "%s", which is not on the Eloquent '
                    . 'model %s\'s table "%s". Rename the sort column, add the column, or drop ->sortable().',
                    $sort->key(),
                    $type,
                    $sort->column,
                    $modelClass,
                    $model->getTable(),
                );
            }
        }

        foreach ($resource->filters() as $filter) {
            $column = $this->filterColumn($filter);
            if ($column !== null && !$known($column)) {
                $problems[] = \sprintf(
                    'The filter "%s" on type "%s" resolves to column "%s", which is not on the Eloquent model '
                    . '%s\'s table "%s". Rename the filter column, add the column, or drop the filter.',
                    $filter->key(),
                    $type,
                    $column,
                    $modelClass,
                    $model->getTable(),
                );
            }
        }
    }

    /**
     * The single table column a column-backed filter targets (its public `column`
     * property), or `null` for a relationship / id / presence / computed filter that
     * carries none.
     */
    private function filterColumn(FilterInterface $filter): ?string
    {
        $reflection = new \ReflectionObject($filter);
        if (!$reflection->hasProperty('column')) {
            return null;
        }

        $property = $reflection->getProperty('column');
        if (!$property->isPublic()) {
            return null;
        }

        $value = $property->getValue($filter);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The Eloquent model FQCN backing `$type`, or `null` when the type is not served by
     * an {@see EloquentDataProvider} (a POPO / in-memory type has no model to introspect).
     *
     * @return class-string<Model>|null
     */
    private function eloquentModelClassFor(string $type): ?string
    {
        if (!$this->providers->supportsType($type)) {
            return null;
        }

        $provider = $this->providers->forType($type);

        return $provider instanceof EloquentDataProvider ? $provider->modelClassFor($type) : null;
    }
}
