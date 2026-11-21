<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToBuilder;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The `playlists` type on the admin server: {@see PlaylistResource} with `owner` writable,
 * since `users` is served here and an admin client can name the user to hand a playlist
 * to. Each server reads only its own class's attribute, so this one repeats the default
 * class's, as the Symfony example's admin playlist class does.
 */
#[AsJsonApiResource(
    server: ['admin'],
    abilities: [
        Operation::Update->value => 'curate',
        Operation::Delete->value => 'deletePlaylist',
    ],
    tags: ['Library'],
    operationScopes: ['delete' => ['library:write', 'library:admin']],
)]
final class PlaylistAdminResource extends PlaylistResource
{
    protected function owner(): BelongsToBuilder
    {
        return BelongsTo::make('owner', 'users')
            ->security(read: 'inspectOwner')
            ->withoutRelatedEndpoint()
            ->exampleId('1');
    }
}
