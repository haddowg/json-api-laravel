<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Action;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use Workbench\App\MusicCatalog\Models\Album;

/**
 * `POST /albums/-actions/summary` — the **collection-scope** custom action (byte-compat twin
 * of the Symfony example's `SummarizeAlbums`): no `{id}`, so the invoker resolves no entity.
 * It computes a catalogue-wide summary (album counts by release lifecycle) and returns it as
 * a meta-only JSON:API document — a non-CRUD report. `responds: [new MetaResult()]` makes the
 * generated OpenAPI document advertise a `200` meta document rather than an albums resource body.
 */
#[AsJsonApiAction(type: 'albums', path: 'summary', scope: ActionScope::Collection, responds: [new MetaResult()], tags: ['Catalog'])]
final class SummarizeAlbums implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $released = Album::query()->where('status', 'released')->count();
        $unreleased = Album::query()->where('status', '!=', 'released')->count();

        return $context->meta([
            'albums' => [
                'released' => $released,
                'unreleased' => $unreleased,
                'total' => $released + $unreleased,
            ],
        ]);
    }
}
