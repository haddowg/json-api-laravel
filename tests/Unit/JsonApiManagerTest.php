<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\JsonApiManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(JsonApiManager::class)]
final class JsonApiManagerTest extends TestCase
{
    public function test_routes_are_registered_by_default(): void
    {
        self::assertTrue((new JsonApiManager())->shouldRegisterRoutes());
    }

    public function test_ignore_routes_suppresses_registration(): void
    {
        $manager = new JsonApiManager();
        $manager->ignoreRoutes();

        self::assertFalse($manager->shouldRegisterRoutes());
    }

    public function test_discover_appends_paths(): void
    {
        $manager = new JsonApiManager();
        $manager->discover('/one')->discover(['/two', '/three']);

        self::assertSame(['/one', '/two', '/three'], $manager->discoveryPaths());
    }

    public function test_register_appends_classes(): void
    {
        $manager = new JsonApiManager();
        $manager->register(\stdClass::class)->register([\ArrayObject::class]);

        self::assertSame([\stdClass::class, \ArrayObject::class], $manager->registeredClasses());
    }

    public function test_provider_registrations_carry_priority(): void
    {
        $provider = new InMemoryDataProvider('artists', []);

        $manager = new JsonApiManager();
        $manager->provider($provider, -128);

        $registrations = $manager->providerRegistrations();
        self::assertCount(1, $registrations);
        self::assertSame($provider, $registrations[0]['provider']);
        self::assertSame(-128, $registrations[0]['priority']);
    }
}
