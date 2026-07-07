<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The `catalog-exports` resource — the per-operation response-declaration witness
 * (the byte-compat twin of the bundle example's `CatalogExportResource`):
 *
 * - **`create` is always async**: `create: [new Accepted('export-jobs')]` declares a
 *   single `202` whose body is the `export-jobs` job document (with `Content-Location`
 *   + `Retry-After`). The paired {@see \Workbench\App\MusicCatalog\Provider\CatalogExportPersister}
 *   returns an {@see \haddowg\JsonApiLaravel\DataPersister\AcceptedForProcessing}.
 * - **`delete` advertises both spec-valid success codes**: `[new NoContent(), new MetaResult()]`
 *   (`204` and a `200` meta-only document); the runtime returns the `204`.
 *
 * Fetch-one/fetch-collection keep their default `200`. Like `charts`/`countries` it has
 * no Eloquent model — a custom provider + persister serve it on both provider arms.
 */
#[AsJsonApiResource(
    operations: [Operation::Create, Operation::FetchOne, Operation::FetchCollection, Operation::Delete],
    create: [new Accepted('export-jobs')],
    delete: [new NoContent(), new MetaResult()],
)]
final class CatalogExportResource extends AbstractResource
{
    public static string $type = 'catalog-exports';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('format')->required()->maxLength(16),
            Str::make('status')->readOnly(),
        ];
    }
}
