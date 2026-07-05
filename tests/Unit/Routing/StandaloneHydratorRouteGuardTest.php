<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Routing;

use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\Routing\RouteRegistrar;
use haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator\BeaconHydrator;
use haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator\BeaconSerializer;
use haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator\DeleteOnlySerializer;
use haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator\IngestCommandHydrator;
use haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator\UnpairedWriteSerializer;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The route registrar's standalone write surface (the Laravel twin of the bundle's
 * compile-time `validateWriteCapability` guard + its operation-gated write routes): a
 * hydrator-paired standalone type emits exactly the verbs its allow-list opens, a
 * `Create`/`Update` allow-list with no hydrator is refused loudly at registration,
 * `Delete` alone needs no hydrator, and a hydrator-only type emits nothing.
 *
 * @internal
 */
#[CoversClass(RouteRegistrar::class)]
final class StandaloneHydratorRouteGuardTest extends TestCase
{
    public function test_a_hydrator_paired_type_emits_the_write_routes_its_allow_list_opens(): void
    {
        $router = $this->registerRoutes([BeaconSerializer::class, BeaconHydrator::class]);
        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        foreach (['index', 'create', 'show', 'update', 'delete'] as $action) {
            self::assertNotNull($routes->getByName('jsonapi.beacons.' . $action), "Route jsonapi.beacons.{$action} should be registered.");
        }

        // A resource-less type declares no relations, so the relation/mutation routes an
        // AbstractResource gets are never emitted for it.
        self::assertNull($routes->getByName('jsonapi.beacons.related.show'));
        self::assertNull($routes->getByName('jsonapi.beacons.relationship.show'));
        self::assertNull($routes->getByName('jsonapi.beacons.relationship.update'));
    }

    public function test_a_write_allow_list_without_a_hydrator_is_refused_at_registration(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'The JSON:API type "unpaired-notes" exposes a write operation (Create) but has no hydrator; '
            . 'register #[AsJsonApiHydrator(type: "unpaired-notes")] or use an AbstractResource.',
        );

        $this->registerRoutes([UnpairedWriteSerializer::class]);
    }

    public function test_delete_alone_needs_no_hydrator(): void
    {
        // Delete hydrates nothing (load-then-remove), so it is legal without a hydrator —
        // only Create/Update trip the guard; the servability warmer holds Delete to a
        // persister instead.
        $router = $this->registerRoutes([DeleteOnlySerializer::class]);
        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        self::assertNotNull($routes->getByName('jsonapi.retirements.show'));
        self::assertNotNull($routes->getByName('jsonapi.retirements.delete'));
        self::assertNull($routes->getByName('jsonapi.retirements.create'));
    }

    public function test_a_hydrator_only_type_emits_no_routes(): void
    {
        // The operation-gating default: a hydrator declares no allow-list of its own, so
        // a hydrator-only type is unrouted — endpoints are opened only by a paired
        // serializer's allow-list (or a resource's).
        $router = $this->registerRoutes([IngestCommandHydrator::class]);

        self::assertSame([], $router->getRoutes()->getRoutes());
    }

    /**
     * Registers the default server's routes for the explicitly given capability classes
     * on a bare router — the pure descriptor → route function under test.
     *
     * @param list<class-string> $classes
     */
    private function registerRoutes(array $classes): Router
    {
        $discovery = new Discovery(new DiscoveryScanner(), [], $classes);
        $router = new Router(new Dispatcher());

        (new RouteRegistrar($discovery, ['default' => []]))->registerConfiguredServers($router);

        return $router;
    }
}
