<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\Email;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Ip;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Validation\Constraint\UniqueEntity;

/**
 * The `users` resource type (music-catalog domain) — the admin-only multi-server witness
 * (`server: 'admin'`, so `/users` 404s and `/admin/users` resolves) plus the validation
 * composition showcase: format subtypes ({@see Email}/{@see Ip}), a dynamic-key
 * {@see ArrayHash}, a genuine write-only `password`, and the composition trio on
 * `passwordConfirm` (an AtLeastOneOf, a conditional When, and an equality CompareField).
 * `email` carries a {@see UniqueEntity} rule (Laravel: `Rule::unique` pre-hydration).
 */
#[AsJsonApiResource(server: 'admin')]
final class UserResource extends AbstractResource
{
    public static string $type = 'users';

    public function fields(): array
    {
        return [
            Id::make(),
            Email::make('email')->required()->strict()->constrain(new UniqueEntity(['email'])),
            Str::make('displayName')->storedAs('display_name')->required(),
            Date::make('birthDate')->storedAs('birth_date')->nullable(),
            ArrayHash::make('preferences')->minProperties(0)->maxProperties(20)->sortKeys(),
            Ip::make('lastSeenIp')->storedAs('last_seen_ip')->nullable(),
            Str::make('password')->writeOnly()->minLength(8)->requiredOnCreate(),
            Str::make('passwordConfirm')
                ->computed()
                ->writeOnly()
                ->atLeastOneOf(
                    new MinLength(8),
                    new Pattern('^.*[0-9].*$'),
                )
                ->when(
                    static fn(mixed $value): bool => $value !== null && $value !== '',
                    static function (Str $field): void {
                        $field->minLength(8);
                    },
                )
                ->compareWith('password', Comparison::EqualTo),
            HasMany::make('playlists', 'playlists'),
            HasOne::make('library', 'libraries'),
        ];
    }

    /**
     * Include safeguard C: the dotted include paths a client may request with `users` as the
     * root — playlists, each playlist's owner, and the library, but NOT `playlists.tracks`.
     *
     * @return list<string>
     */
    public function getAllowedIncludePaths(): array
    {
        return ['playlists', 'playlists.owner', 'library'];
    }
}
