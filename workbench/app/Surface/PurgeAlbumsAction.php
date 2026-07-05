<?php

declare(strict_types=1);

namespace Workbench\App\Surface;

use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A custom, **collection-scope** action `POST /albums/-actions/purge` (PLAN decision 12):
 * it hangs off the `albums` collection with no `{id}`, so the invoker resolves no entity
 * and the declared `purge` ability is authorized against the resource-class token — not a
 * loaded instance. It exists to prove a collection-scope action's ability gate is
 * enforced (never fail-open) when the type declares no dedicated `policy:` class and the
 * ability is a Gate-registered closure.
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'purge',
    scope: ActionScope::Collection,
    ability: 'purge',
    outputMeta: true,
)]
final readonly class PurgeAlbumsAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['purged' => true]);
    }
}
