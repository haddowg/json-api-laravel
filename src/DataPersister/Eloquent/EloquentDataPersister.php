<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataPersister\Eloquent;

use haddowg\JsonApi\Exception\ClientGeneratedIdAlreadyExists;
use haddowg\JsonApiLaravel\DataPersister\AbstractDataPersister;
use haddowg\JsonApiLaravel\DataPersister\TransactionalDataPersisterInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
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
final class EloquentDataPersister extends AbstractDataPersister implements TransactionalDataPersisterInterface
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
     * @param array<string, class-string<Model>> $modelByType a `type → Eloquent model FQCN` map (the SAME map the read provider uses)
     * @param string|null                        $connection  the connection name the batch transaction controls run on (null = the default connection); the per-op writes use each model's own connection
     */
    public function __construct(array $modelByType, private readonly ?string $connection = null)
    {
        $this->modelByType = $modelByType;
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
