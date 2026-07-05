<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Lifecycle;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `relics` fixture resource for the response-headers suite: it declares declarative
 * HTTP cache directives (a resource-level default plus a longer-lived `collection`
 * per-read-shape override) and an RFC 8594 deprecation/sunset signal on the attribute, so
 * the {@see \haddowg\JsonApiLaravel\Http\ResponseHeadersMiddleware} projects them onto the
 * response — cache on a successful GET only, deprecation/sunset on every response.
 *
 * @internal
 */
#[AsJsonApiResource(
    cacheHeaders: [
        'max_age' => 60,
        'public' => true,
        'vary' => ['Accept'],
        'operations' => [
            'collection' => ['max_age' => 3600],
        ],
    ],
    deprecation: true,
    sunset: 'Wed, 11 Nov 2026 00:00:00 GMT',
    sunsetLink: 'https://example.test/relics-sunset',
)]
final class RelicResource extends AbstractResource
{
    public static string $type = 'relics';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
