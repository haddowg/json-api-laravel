<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\IdPattern\IdPatternServiceProvider;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A type whose Id declares a route pattern (PLAN decision 4, core ADR 0038) has that pattern
 * composed into the `{id}` route requirement, so a malformed id 404s at routing rather than
 * reaching the handler — keeping the runtime consistent with the `idPattern` the projected
 * OpenAPI document advertises.
 *
 * @internal
 */
final class IdRoutePatternTest extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            IdPatternServiceProvider::class,
        ];
    }

    #[Test]
    #[Group('routing')]
    public function theDeclaredNumericPatternIsComposedIntoTheIdRequirement(): void
    {
        $router = $this->app?->make('router');
        self::assertInstanceOf(Router::class, $router);

        $route = $router->getRoutes()->getByName('jsonapi.numeric_things.show');
        self::assertInstanceOf(Route::class, $route);

        $requirement = $route->wheres['id'] ?? '';
        // The reserved -actions lookahead wraps the declared numeric body.
        $this->assertStringContainsString('[0-9]+', $requirement);
        $this->assertStringContainsString('-actions', $requirement);
    }

    #[Test]
    #[Group('routing')]
    public function aNumericIdIsServedAndANonNumericIdIsNotRouted(): void
    {
        $this->getJson('/api/numeric_things/1', ['Accept' => 'application/vnd.api+json'])
            ->assertOk()
            ->assertJsonPath('data.id', '1');

        // A non-numeric id does not match the composed requirement, so no route matches → 404.
        $this->getJson('/api/numeric_things/not-a-number', ['Accept' => 'application/vnd.api+json'])
            ->assertNotFound();
    }
}
