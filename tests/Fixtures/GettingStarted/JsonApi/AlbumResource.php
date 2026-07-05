<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\GettingStarted\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The getting-started `albums` resource — deliberately verbatim from
 * [docs/getting-started.md] step 1: fields only, no `#[AsJsonApiResource]`, no `model:`,
 * no provider/persister wiring anywhere. Dropped in the scanned directory, it must yield
 * all five CRUD endpoints backed by the auto-registered reference Eloquent pair mapping
 * `albums` → the convention-named {@see \haddowg\JsonApiLaravel\Tests\Fixtures\GettingStarted\Models\Album}.
 *
 * @internal
 */
final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(200)->sortable(),
        ];
    }
}
