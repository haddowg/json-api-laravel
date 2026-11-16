<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Email;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\SingletonIdResolver;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The `accounts` singleton: the signed-in user's own account, served at `/me` with no id.
 *
 * `GET /me` reads it and `PATCH /me` renames it; `/me/playlists` and
 * `/me/relationships/playlists` reach the user's playlists. It is a third view of the
 * catalogue's users beside the admin-only `users` and the public `public-profiles`, and like
 * them it keeps the row's real id, so the `PATCH` body carries it.
 *
 * {@see resolveSingletonId()} names the signed-in user's row, which shares its id with the
 * user; a guest gets a `404`, as does a user with no row. The
 * {@see \Workbench\App\MusicCatalog\Models\Account} model's global scope, and the in-memory
 * {@see \Workbench\App\MusicCatalog\Provider\OwnAccountProvider}, narrow the type to that
 * same row, so no other user's account can render with a `/me` link. The Symfony example
 * declares the same type.
 */
#[AsJsonApiResource(tags: ['Library'])]
final class AccountResource extends AbstractResource
{
    public static string $type = 'accounts';

    public static string $uriType = 'me';

    public static bool $singleton = true;

    public function resolveSingletonId(SingletonContext $context): ?string
    {
        $user = $context->attribute(SingletonIdResolver::USER_ATTRIBUTE);
        $id = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;

        return \is_int($id) || \is_string($id) ? (string) $id : null;
    }

    public function fields(): array
    {
        return [
            Id::make()->example('1'),
            Email::make('email')->example('ada@example.com')->readOnly(),
            Str::make('displayName')->example('Ada')->storedAs('display_name')->minLength(1),
            HasMany::make('playlists', 'playlists'),
        ];
    }
}
