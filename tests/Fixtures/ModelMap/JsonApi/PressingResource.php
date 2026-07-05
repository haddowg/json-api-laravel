<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The convention-tier witness (ADR 0019): no attribute, no wiring — `pressings`
 * resolves to the fixture `Pressing` model purely by name under the test's configured
 * `jsonapi.eloquent.model_namespace`.
 *
 * @internal
 */
final class PressingResource extends AbstractResource
{
    public static string $type = 'pressings';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}
