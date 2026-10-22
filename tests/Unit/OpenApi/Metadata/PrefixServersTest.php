<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApiLaravel\OpenApi\Metadata\PrefixServers;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The OpenAPI `servers` an empty-base server advertises, read from the prefixes on its
 * registered routes (ADR 0028).
 *
 * @internal
 */
#[CoversClass(PrefixServers::class)]
final class PrefixServersTest extends TestCase
{
    #[Test]
    public function aPrefixBecomesARelativeServerUrl(): void
    {
        self::assertSame(
            [['url' => '/api']],
            self::urls(PrefixServers::fromRoutes([self::route('default', 'api'), self::route('default', 'api', 'albums/{id}')], 'default')),
        );
    }

    #[Test]
    public function routesWithNoPrefixAdvertiseNoServers(): void
    {
        self::assertSame([], PrefixServers::fromRoutes([self::route('default', null)], 'default'));
        self::assertSame([], PrefixServers::fromRoutes([], 'default'));
    }

    #[Test]
    public function eachDistinctPrefixIsListedSortedWithAnUnprefixedRouteAsRoot(): void
    {
        $routes = [
            self::route('default', 'v2/api'),
            self::route('default', null),
            self::route('default', 'api'),
        ];

        self::assertSame(
            [['url' => '/'], ['url' => '/api'], ['url' => '/v2/api']],
            self::urls(PrefixServers::fromRoutes($routes, 'default')),
        );
    }

    #[Test]
    public function onlyTheNamedServersJsonApiRoutesCount(): void
    {
        $foreign = new Route(['GET'], 'health', ['prefix' => 'ops']);

        $routes = [
            self::route('admin', 'admin'),
            self::route('default', 'api'),
            $foreign->defaults('_jsonapi_server', 'default'),
        ];

        self::assertSame([['url' => '/api']], self::urls(PrefixServers::fromRoutes($routes, 'default')));
        self::assertSame([['url' => '/admin']], self::urls(PrefixServers::fromRoutes($routes, 'admin')));
    }

    #[Test]
    public function aPlaceholderBecomesAVariableDefaultingToTheRouteDefault(): void
    {
        $route = self::route('default', '{tenant}/api')->defaults('tenant', 'acme');

        self::assertSame(
            [['url' => '/{tenant}/api', 'variables' => ['tenant' => ['default' => 'acme']]]],
            self::urls(PrefixServers::fromRoutes([$route], 'default')),
        );
    }

    #[Test]
    public function aPlaceholderWithoutARouteDefaultIsLeftOut(): void
    {
        $routes = [self::route('default', '{tenant}/api'), self::route('default', 'api')];

        self::assertSame([['url' => '/api']], self::urls(PrefixServers::fromRoutes($routes, 'default')));
    }

    private static function route(string $server, ?string $prefix, string $uri = 'albums'): Route
    {
        return (new Route(['GET'], $uri, ['prefix' => $prefix]))
            ->defaults('_jsonapi_server', $server)
            ->defaults('_jsonapi', true);
    }

    /**
     * @param list<Server> $servers
     *
     * @return list<array<string, mixed>>
     */
    private static function urls(array $servers): array
    {
        return \array_map(static fn(Server $server): array => $server->toArray(), $servers);
    }
}
