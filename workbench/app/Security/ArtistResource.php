<?php

declare(strict_types=1);

namespace Workbench\App\Security;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The secured `artists` type demonstrating the two Gate-driven resolution paths (PLAN
 * decision 7), Eloquent-only (a POPO carries no Gate policy). It declares NO `policy:`
 * attribute, so the {@see \haddowg\JsonApiLaravel\Authorization\Authorizer} resolves
 * through the application Gate:
 *  - `view`/`create`/`update`/`delete` resolve to the **model-registered policy**
 *    ({@see \Workbench\App\Security\Policies\ArtistApiPolicy}, mapped with
 *    `Gate::policy(Artist::class, …)` in the wiring);
 *  - the list ability is renamed to `browseArtists`, which that policy lacks, so the Gate
 *    falls through to a `Gate::define('browseArtists', …)` closure — the **Gate::define**
 *    path (proving a renamed ability is Gate-resolved).
 *
 * It lives outside the scanned `app/JsonApi` path (registered explicitly) so it never
 * collides with the music-suite `artists` resource.
 */
#[AsJsonApiResource(
    abilities: [
        Operation::FetchCollection->value => 'browseArtists',
    ],
)]
final class ArtistResource extends AbstractResource
{
    public static string $type = 'artists';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required(),
            Str::make('slug')->required(),
        ];
    }
}
