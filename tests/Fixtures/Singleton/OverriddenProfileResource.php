<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Singleton;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A resource that isn't a singleton itself, rendered through a serializer that declares
 * the type one.
 *
 * @internal
 */
#[AsJsonApiResource(type: 'settings', serializer: SettingsSerializer::class)]
final class OverriddenProfileResource extends AbstractResource
{
    public static string $type = 'settings';

    public function fields(): array
    {
        return [Id::make()];
    }
}
