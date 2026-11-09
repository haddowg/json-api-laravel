<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Action;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionInputFactoryInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use Workbench\App\MusicCatalog\Model\Reissue;

/**
 * The `reissue` album action `POST /albums/{id}/-actions/reissue` (music-catalog domain) —
 * the resource-scope + Document-input + ability-secured custom action (the byte-compat twin
 * of the Symfony example's `ReissueAlbum`). Its `inputType` is the related-only `reissues`
 * type, hydrated into the blank {@see Reissue} {@see newInput()} supplies. It reads the
 * target album (`{id}` resolved to the entity), applies the reissue's `releasedAt`, persists
 * through the `albums` persister, and returns the updated album. `ability: 'reissueAlbum'`
 * gates it through the package Authorizer / Gate (a denied requester is a `403`).
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'reissue',
    input: ActionInput::Document,
    inputType: 'reissues',
    ability: 'reissueAlbum',
    tags: ['Catalog'],
    asLink: true,
)]
final readonly class ReissueAlbum implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function __construct(private DataPersisterRegistry $persisters) {}

    public function newInput(JsonApiRequestInterface $body): object
    {
        return new Reissue();
    }

    public function handle(ActionContext $context): DataResponse
    {
        $album = $context->entity();
        \assert($album !== null);

        $input = $context->input();
        \assert($input instanceof Reissue);
        Accessor::set($album, 'released_at', $input->releasedAt);

        $persisted = $this->persisters->forType('albums')->update('albums', $album);

        return $context->data($persisted);
    }
}
