<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

/**
 * Tells a coin from a mark when a chest's polymorphic `holdings` resolve each member's serializer.
 */
trait DiscriminatesHolding
{
    public function getType(mixed $object): string
    {
        return $object instanceof Mark || $object instanceof MarkRecord ? 'marks' : 'coins';
    }
}
