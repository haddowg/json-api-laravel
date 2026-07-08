<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataPersister;

/**
 * The segregated soft-delete write capability a {@see DataPersisterInterface} may also
 * implement (the reference {@see \haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister}
 * does): {@see restore()} un-trashes a soft-deleted entity, {@see forceDelete()} removes it
 * permanently. The package's synthesized `restore` / `force-delete` action handlers commit
 * through these — resolving the type's persister and using this capability when present, or
 * falling back to the entity's own `restore()`/`forceDelete()` for a store that does not
 * implement it.
 *
 * The ordinary {@see DataPersisterInterface::delete()} is unchanged and stays a **soft**
 * delete on a soft-deletable model (`$model->delete()` sets the tombstone); this capability
 * is only the extra restore/permanent-removal seam, so a persister opts in exactly like the
 * transactional {@see TransactionalDataPersisterInterface}.
 */
interface SoftDeleteCapable
{
    /**
     * Restores a soft-deleted entity of `$type` (clears its tombstone) and returns it.
     */
    public function restore(string $type, object $entity): object;

    /**
     * Permanently removes an entity of `$type` from the store, bypassing the soft-delete
     * tombstone.
     */
    public function forceDelete(string $type, object $entity): void;
}
