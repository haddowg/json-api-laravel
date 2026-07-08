<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataPersister\SoftDeleteCapable;
use Illuminate\Database\Eloquent\Model;

/**
 * The package-shipped, **type-agnostic** handler for the synthesized `restore` action
 * (`POST /{uriType}/{id}/-actions/restore`) of any soft-deletable resource
 * ({@see \haddowg\JsonApiLaravel\Attribute\SoftDeletes}). It reads its mount type off the
 * {@see ActionContext} descriptor, so one instance serves every soft-deletable type; the
 * trashed target is resolved by the invoker through the trashed-inclusive fetch
 * ({@see \haddowg\JsonApiLaravel\DataProvider\FetchesTrashed}, driven by the descriptor's
 * `resolvesTrashed` flag) before this runs.
 *
 * It restores through the type's persister when it is {@see SoftDeleteCapable} (the reference
 * Eloquent persister is), falling back to the Eloquent model's own `restore()` otherwise, and
 * returns the now-untrashed resource as a `200` document.
 *
 * As {@see ConditionallyLinked} its `asLink` link renders only on a **trashed** resource, so
 * a live resource never advertises a restore it does not need (while the `restore` ability
 * gate independently hides it from a requester who could not invoke it).
 */
final readonly class RestoreAction implements ActionHandlerInterface, ConditionallyLinked
{
    public function __construct(private DataPersisterRegistry $persisters) {}

    public function handle(ActionContext $context): DataResponse
    {
        $entity = $context->entity();
        \assert($entity !== null); // resource scope: the invoker resolved the trashed target (else a 404)

        $type = $context->descriptor()->type;
        $persister = $this->persisters->forType($type);

        if ($persister instanceof SoftDeleteCapable) {
            $restored = $persister->restore($type, $entity);
        } else {
            \assert($entity instanceof Model && \method_exists($entity, 'restore'));
            $entity->restore();
            $restored = $entity;
        }

        return $context->data($restored);
    }

    public function shouldLink(object $entity): bool
    {
        return $entity instanceof Model && \method_exists($entity, 'trashed') && $entity->trashed();
    }
}
