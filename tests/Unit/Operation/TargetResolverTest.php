<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Operation;

use haddowg\JsonApiLaravel\Operation\TargetResolver;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TargetResolver::class)]
final class TargetResolverTest extends TestCase
{
    public function test_it_returns_null_when_no_route_is_matched(): void
    {
        $request = Request::create('/artists', 'GET');

        self::assertNull((new TargetResolver())->resolveFromRequest($request));
    }

    public function test_it_returns_null_for_a_non_jsonapi_route(): void
    {
        $request = $this->requestFor('GET', '/artists', '/artists', []);

        self::assertNull((new TargetResolver())->resolveFromRequest($request));
    }

    public function test_it_resolves_a_collection_target(): void
    {
        $request = $this->requestFor('GET', '/artists', '/artists', [
            TargetResolver::TYPE_ATTRIBUTE => 'artists',
            TargetResolver::SERVER_ATTRIBUTE => 'default',
        ]);

        $target = (new TargetResolver())->resolveFromRequest($request);

        self::assertNotNull($target);
        self::assertSame('artists', $target->type);
        self::assertFalse($target->hasId());
        self::assertFalse($target->hasRelationship());
    }

    public function test_it_resolves_a_single_resource_target_with_id(): void
    {
        $request = $this->requestFor('GET', '/artists/7', '/artists/{id}', [
            TargetResolver::TYPE_ATTRIBUTE => 'artists',
        ]);

        $target = (new TargetResolver())->resolveFromRequest($request);

        self::assertNotNull($target);
        self::assertSame('artists', $target->type);
        self::assertSame('7', $target->id);
        self::assertTrue($target->hasId());
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private function requestFor(string $method, string $path, string $uri, array $defaults): Request
    {
        $request = Request::create($path, $method);

        $route = new Route([$method], $uri, []);
        foreach ($defaults as $key => $value) {
            $route->defaults($key, $value);
        }
        $route->bind($request);

        $request->setRouteResolver(static fn(): Route => $route);

        return $request;
    }
}
