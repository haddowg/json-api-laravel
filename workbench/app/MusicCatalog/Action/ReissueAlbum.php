<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Action;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;

/**
 * The `reissue` album action `POST /albums/{id}/-actions/reissue` (music-catalog domain) —
 * the resource-scope + Document-input + ability-secured custom action (the byte-compat twin
 * of the Symfony example's `ReissueAlbum`). It reads the target album (`{id}` resolved to
 * the entity), applies the Document input's `status` (defaulting to `released`), persists
 * through the `albums` persister, and returns the updated album. `ability: 'reissueAlbum'`
 * gates it through the package Authorizer / Gate (a denied requester is a `403`).
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'reissue',
    input: ActionInput::Document,
    ability: 'reissueAlbum',
    tags: ['Catalog'],
    asLink: true,
)]
final readonly class ReissueAlbum implements ActionHandlerInterface
{
    public function __construct(private DataPersisterRegistry $persisters) {}

    public function handle(ActionContext $context): DataResponse
    {
        $album = $context->entity();
        \assert($album !== null);

        $input = $context->input();
        $status = $input !== null ? Accessor::get($input, 'status') : null;
        Accessor::set($album, 'status', \is_string($status) && $status !== '' ? $status : 'released');

        $persisted = $this->persisters->forType('albums')->update('albums', $album);

        return $context->data($persisted);
    }
}
