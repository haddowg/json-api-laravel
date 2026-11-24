<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

/**
 * A key held as a value object, its text the stored value.
 */
final readonly class IkKey implements \Stringable
{
    public function __construct(public string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
