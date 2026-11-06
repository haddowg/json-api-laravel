<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\DeprecationErrors;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Declares a sunset that is not a date.
 *
 * @internal
 */
#[AsJsonApiResource(readOnly: true, deprecation: true, sunset: 'eventually')]
final class BadSunsetResource extends AbstractResource
{
    public static string $type = 'relays';

    public function fields(): array
    {
        return [Id::make()];
    }
}
