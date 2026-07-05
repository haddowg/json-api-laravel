<?php

declare(strict_types=1);

namespace Workbench\App\Surface;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;

/**
 * A custom, resource-scope action `POST /albums/{id}/-actions/publish` (PLAN decision 12
 * showcase, mirroring the Symfony example's album-publish action). It demonstrates:
 *  - **resource scope** — the `{id}` is resolved to the target album before the handler runs
 *    ({@see ActionContext::entity()});
 *  - **Document input** — the request body is validated + hydrated into a fresh `albums`
 *    instance ({@see ActionContext::input()}), from which the new status is read;
 *  - **ability security** — `#[AsJsonApiAction(ability: 'publish')]` gates the action through
 *    the package Authorizer / Gate (a denied requester is a `403`);
 *  - **asLink** — the action's URL is merged onto every rendered album's `links` (only when
 *    the requester would pass the `publish` gate).
 *
 * The handler applies the input's `status` onto the loaded album, persists it through the
 * `albums` persister, and returns the updated album as a resource document.
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'publish',
    input: ActionInput::Document,
    ability: 'publish',
    asLink: true,
)]
final readonly class PublishAlbumAction implements ActionHandlerInterface
{
    public function __construct(private DataPersisterRegistry $persisters) {}

    public function handle(ActionContext $context): DataResponse
    {
        $album = $context->entity();
        \assert($album !== null); // resource scope: the invoker resolved it (else a 404)

        // Apply the incoming status from the validated + hydrated Document input onto the
        // loaded album, then persist through the `albums` persister — the framework-neutral
        // Accessor reads/writes the property on the in-memory POPO and the Eloquent model
        // alike.
        $input = $context->input();
        $status = $input !== null ? Accessor::get($input, 'status') : null;
        Accessor::set($album, 'status', \is_string($status) && $status !== '' ? $status : 'released');

        $persisted = $this->persisters->forType('albums')->update('albums', $album);

        return $context->data($persisted);
    }
}
