<?php

declare(strict_types=1);

namespace Workbench\App\Security;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;
use Workbench\App\Security\Policies\AlbumApiPolicy;

/**
 * The secured `albums` type for the authorization conformance suite (PLAN decision 7),
 * served on the auth-guarded `secure` server so an unauthenticated request is a `401`
 * (the auth middleware) while an authenticated-but-denied one is a `403` (the policy).
 *
 * It exercises the dedicated-API-policy path plus two attribute overrides:
 *  - `policy: AlbumApiPolicy::class` — the dedicated policy invoked directly, provider-
 *    agnostic (it authorizes both the in-memory POPO and the Eloquent model), leaving
 *    the application's `Gate::policy()` mapping untouched;
 *  - `abilities`: `create` renamed to `publish` (the API-distinct ability) and `delete`
 *    set to `false` (the check is disabled — an authenticated user may delete regardless
 *    of the policy).
 *
 * It lives outside the scanned `app/JsonApi` path (registered explicitly by the security
 * wiring) so it never collides with the music-suite `albums` resource.
 */
#[AsJsonApiResource(
    server: 'secure',
    policy: AlbumApiPolicy::class,
    abilities: [
        Operation::Create->value => 'publish',
        Operation::Delete->value => false,
    ],
)]
final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required(),
            Str::make('status'),
            DateTime::make('releasedAt')->storedAs('released_at'),
        ];
    }
}
