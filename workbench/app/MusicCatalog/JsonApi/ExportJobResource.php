<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\OpenApi\Metadata\Ok;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\ResolvesCompletionRedirect;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use Workbench\App\MusicCatalog\Domain\ExportJob;

/**
 * The `export-jobs` resource — the read side of the async-write witness and the
 * byte-compat twin of the bundle example's `ExportJobResource`. It is read-only and
 * its fetch-one declares BOTH JSON:API asynchronous-processing outcomes:
 * `fetchOne: [new Ok(), new SeeOther()]` → a `200` status document while the job runs,
 * or a `303 See Other` to the produced resource once complete.
 *
 * The `303` is driven at runtime by {@see ResolvesCompletionRedirect}: the fetch-one
 * handler consults {@see completionLocation()} after loading the job and, when it returns
 * a URL, renders a {@see \haddowg\JsonApi\Response\SeeOtherResponse} instead of the `200`.
 */
#[AsJsonApiResource(
    readOnly: true,
    fetchOne: [new Ok(), new SeeOther()],
)]
final class ExportJobResource extends AbstractResource implements ResolvesCompletionRedirect
{
    public static string $type = 'export-jobs';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('state')->readOnly(),
        ];
    }

    public function completionLocation(object $entity): ?string
    {
        \assert($entity instanceof ExportJob);

        return $entity->state === 'completed'
            ? url('/api/catalog-exports/' . $entity->exportId)
            : null;
    }
}
