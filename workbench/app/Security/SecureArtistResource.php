<?php

declare(strict_types=1);

namespace Workbench\App\Security;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A minimal `artists` type on the auth-guarded `secure` server, registered purely so the
 * secured {@see AlbumResource}'s `artist` to-one relations resolve a related serializer
 * there. It is the RELATED target of those relations, never a gated parent itself — the
 * per-relation READ gate always authorizes the album (the parent), so this type needs no
 * policy of its own.
 *
 * It lives outside the scanned `app/JsonApi` path (registered explicitly by the security
 * wiring) so it never collides with the music-suite `artists` resource, and it is distinct
 * from the default-server {@see ArtistResource} that demonstrates the Gate-driven paths.
 */
#[AsJsonApiResource(server: 'secure', readOnly: true)]
final class SecureArtistResource extends AbstractResource
{
    public static string $type = 'artists';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
