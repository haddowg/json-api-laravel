<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures;

/**
 * The concrete {@see Clock} bound to the interface in the container, returning a fixed,
 * recognisable label so a test can assert the exact value flowed from the injected
 * dependency into the rendered document.
 *
 * @internal
 */
final class FixedClock implements Clock
{
    public const string LABEL = 'stamped-by-container';

    public function label(): string
    {
        return self::LABEL;
    }
}
