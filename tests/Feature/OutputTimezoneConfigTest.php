<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * `jsonapi.output_timezone` and each server's `output_timezone` reach the core server
 * they configure, and a zone PHP does not recognise fails the boot.
 *
 * @internal
 */
#[CoversNothing]
final class OutputTimezoneConfigTest extends TestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function zones($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.output_timezone', 'UTC');
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api'],
            'admin' => ['prefix' => 'admin', 'output_timezone' => 'Europe/Paris'],
            'local' => ['prefix' => 'local', 'output_timezone' => ''],
        ]);
    }

    #[Test]
    public function absentConfigLeavesEveryServerWithoutAZone(): void
    {
        self::assertNull($this->app?->make(ServerRegistry::class)->get()->outputTimezone());
    }

    #[Test]
    #[DefineEnvironment('zones')]
    public function eachServerGetsItsOwnZoneOrTheGlobalOne(): void
    {
        $registry = $this->app?->make(ServerRegistry::class);
        \assert($registry instanceof ServerRegistry);

        self::assertSame('UTC', $registry->get('default')->outputTimezone());
        self::assertSame('Europe/Paris', $registry->get('admin')->outputTimezone());
        self::assertNull($registry->get('local')->outputTimezone());
    }

    #[Test]
    public function anUnknownZoneFailsTheBoot(): void
    {
        $app = $this->app;
        \assert($app !== null);
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers.default.output_timezone', 'Europe/Pariss');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jsonapi.servers.default.output_timezone is "Europe/Pariss"');

        (new JsonApiServiceProvider($app))->boot();
    }
}
