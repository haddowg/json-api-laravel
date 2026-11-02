<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\NaiveDateTimeStorage;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

#[CoversNothing]
final class StorageTimezoneConfigTest extends TestCase
{
    private string $previousTimezone;

    protected function setUp(): void
    {
        $this->previousTimezone = \date_default_timezone_get();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        \date_default_timezone_set($this->previousTimezone);
    }

    #[Test]
    public function anAbsentZoneFollowsTheDefaultTimezoneAtEachConversion(): void
    {
        $storage = new NaiveDateTimeStorage();

        \date_default_timezone_set('America/New_York');
        self::assertSame('America/New_York', $storage->zone()->getName());

        \date_default_timezone_set('Europe/Paris');
        self::assertSame('Europe/Paris', $storage->zone()->getName());
    }

    #[Test]
    public function anEmptyZoneMeansTheDefaultTimezone(): void
    {
        $this->app?->make('config')->set('jsonapi.storage_timezone', '');
        \date_default_timezone_set('Europe/Paris');

        self::assertSame('Europe/Paris', (new NaiveDateTimeStorage())->zone()->getName());
    }

    #[Test]
    public function aConfiguredZoneOverridesTheDefault(): void
    {
        $this->app?->make('config')->set('jsonapi.storage_timezone', 'UTC');
        \date_default_timezone_set('Europe/Paris');

        self::assertSame('UTC', (new NaiveDateTimeStorage())->zone()->getName());
    }

    #[Test]
    public function anUnknownZoneFailsTheBoot(): void
    {
        $app = $this->app;
        \assert($app !== null);
        $app['config']->set('jsonapi.storage_timezone', 'Europe/Pariss');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jsonapi.storage_timezone is "Europe/Pariss"');

        (new JsonApiServiceProvider($app))->boot();
    }
}
