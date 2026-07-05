<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Overrides;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A deliberately misconfigured resource: its `serializer:` override names a class that
 * does not implement core's `SerializerInterface`, so discovery must fail loudly (a
 * {@see \LogicException}) rather than defer the fault to a runtime resolver error. Kept
 * out of every scanned path — it is only ever handed to the scanner explicitly.
 *
 * @internal
 */
/* @phpstan-ignore-next-line argument.type (the invalid override IS the fixture) */
#[AsJsonApiResource(serializer: \stdClass::class)]
final class BadSerializerOverrideResource extends AbstractResource
{
    public static string $type = 'bad-overrides';

    public function fields(): array
    {
        return [Id::make()];
    }
}
