<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `devices` resource type (music-catalog domain) — the app-generated ULID id strategy
 * (`ulid()->generated()`), the self-link opt-out ({@see emitsSelfLink()} returns false, so
 * resource objects carry no `data.links.self`), and the RFC 8594 deprecation witness
 * (`deprecation`/`sunset`/`sunsetLink` ride every response for the type).
 */
#[AsJsonApiResource(
    deprecation: true,
    sunset: 'Sat, 31 Dec 2050 23:59:59 GMT',
    sunsetLink: 'https://music.example/deprecations/devices',
)]
final class DeviceResource extends AbstractResource
{
    public static string $type = 'devices';

    /**
     * Opts the `devices` type out of the convention resource `self` link.
     */
    public function emitsSelfLink(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [
            Id::make()->ulid()->generated(),
            Str::make('label')->required(),
        ];
    }
}
