<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Support;

/**
 * Pins the clock for a test that boots the music catalogue, whose track `preview` link
 * renders an `expiresAt` an hour from now, so two renders in one test always match.
 * Testbench calls the setUp hook for each trait a test case uses, and Laravel resets the
 * clock on tearDown.
 *
 * @mixin \Orchestra\Testbench\TestCase
 */
trait PinsCatalogClock
{
    public const string PINNED_NOW = '2026-01-01T11:00:00+00:00';

    protected function setUpPinsCatalogClock(): void
    {
        $this->travelTo(new \DateTimeImmutable(self::PINNED_NOW));
    }
}
