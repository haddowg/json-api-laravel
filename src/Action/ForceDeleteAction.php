<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataPersister\SoftDeleteCapable;
use Illuminate\Database\Eloquent\Model;

/**
 * The package-shipped, **type-agnostic** handler for the synthesized `force-delete` action
 * (`POST /{uriType}/{id}/-actions/force-delete`) of any soft-deletable resource
 * ({@see \haddowg\JsonApiLaravel\Attribute\SoftDeletes}). It reads its mount type off the
 * {@see ActionContext} descriptor, so one instance serves every soft-deletable type; the
 * (typically trashed) target is resolved by the invoker through the trashed-inclusive fetch
 * before this runs.
 *
 * It permanently removes the entity through the type's persister when it is
 * {@see SoftDeleteCapable} (the reference Eloquent persister is), falling back to the Eloquent
 * model's own `forceDelete()` otherwise, and returns a `204`. This is the ONLY path to
 * permanent removal — the ordinary `DELETE` stays a recoverable soft delete.
 */
final readonly class ForceDeleteAction implements ActionHandlerInterface
{
    public function __construct(private DataPersisterRegistry $persisters) {}

    public function handle(ActionContext $context): NoContentResponse
    {
        $entity = $context->entity();
        \assert($entity !== null); // resource scope: the invoker resolved the target (else a 404)

        $type = $context->descriptor()->type;
        $persister = $this->persisters->forType($type);

        if ($persister instanceof SoftDeleteCapable) {
            $persister->forceDelete($type, $entity);
        } else {
            \assert($entity instanceof Model);
            $entity->forceDelete();
        }

        return $context->noContent();
    }
}
