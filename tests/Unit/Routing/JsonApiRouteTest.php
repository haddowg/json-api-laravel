<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Routing;

use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Exception\JsonApiExceptionRenderer;
use haddowg\JsonApiLaravel\Routing\JsonApiRoute;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(JsonApiRoute::class)]
final class JsonApiRouteTest extends TestCase
{
    public function test_a_route_carrying_the_marker_matches(): void
    {
        $route = (new Route(['GET'], 'albums', static fn(): null => null))->defaults(JsonApiRoute::MARKER, true);

        self::assertTrue(JsonApiRoute::matches($this->routedTo($route)));
    }

    public function test_a_route_without_the_marker_does_not_match(): void
    {
        self::assertFalse(JsonApiRoute::matches($this->routedTo(new Route(['GET'], 'plain', static fn(): null => null))));
    }

    #[DataProvider('nonTrueMarkers')]
    public function test_a_marker_that_is_not_exactly_true_does_not_match(mixed $value): void
    {
        $route = (new Route(['GET'], 'albums', static fn(): null => null))->defaults(JsonApiRoute::MARKER, $value);

        self::assertFalse(JsonApiRoute::matches($this->routedTo($route)));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonTrueMarkers(): iterable
    {
        yield 'false' => [false];
        yield 'string "1"' => ['1'];
        yield 'int 1' => [1];
        yield 'null' => [null];
    }

    public function test_an_unrouted_request_does_not_match(): void
    {
        self::assertFalse(JsonApiRoute::matches(Request::create('/albums')));
    }

    public function test_the_macro_delegates_to_matches(): void
    {
        JsonApiRoute::registerMacro();
        $route = (new Route(['GET'], 'albums', static fn(): null => null))->defaults(JsonApiRoute::MARKER, true);

        self::assertTrue($this->routedTo($route)->isJsonApi());
        self::assertFalse(Request::create('/albums')->isJsonApi());
    }

    public function test_of_is_null_without_the_marker_or_a_route(): void
    {
        self::assertNull(JsonApiRoute::of(Request::create('/albums')));
        $route = (new Route(['GET'], 'albums', static fn(): null => null))->defaults('_jsonapi_type', 'albums');
        self::assertNull(JsonApiRoute::of($this->routedTo($route)));
    }

    public function test_of_reads_the_route_defaults_and_parameters(): void
    {
        $route = (new Route(['GET'], 'albums/{id}/relationships/{relationship}', static fn(): null => null))
            ->defaults(JsonApiRoute::MARKER, true)
            ->defaults('_jsonapi_type', 'albums')
            ->defaults('_jsonapi_server', 'v2')
            ->defaults('_jsonapi_relationship_endpoint', true);
        $request = Request::create('/albums/7/relationships/artist');
        $route->bind($request);
        $request->setRouteResolver(static fn(): Route => $route);

        self::assertEquals(new JsonApiRoute('v2', 'albums', '7', 'artist', true), JsonApiRoute::of($request));
    }

    public function test_of_falls_back_to_the_default_server_and_maps_the_action_scope(): void
    {
        $route = (new Route(['POST'], 'albums/-actions/purge', static fn(): null => null))
            ->defaults(JsonApiRoute::MARKER, true)
            ->defaults('_jsonapi_type', 'albums')
            ->defaults('_jsonapi_action', 'purge')
            ->defaults('_jsonapi_action_scope', 'Collection');

        self::assertEquals(
            new JsonApiRoute('default', 'albums', action: 'purge', actionScope: ActionScope::Collection),
            JsonApiRoute::of($this->routedTo($route)),
        );
    }

    public function test_target_carries_type_id_and_relationship(): void
    {
        self::assertEquals(new Target('albums'), (new JsonApiRoute('default', 'albums'))->target());
        self::assertEquals(new Target('albums', '1'), (new JsonApiRoute('default', 'albums', '1'))->target());
        self::assertEquals(new Target('albums', '1', 'artist', false), (new JsonApiRoute('default', 'albums', '1', 'artist'))->target());
        self::assertEquals(new Target('albums', '1', 'artist', true), (new JsonApiRoute('default', 'albums', '1', 'artist', true))->target());
    }

    public function test_target_is_null_without_a_type(): void
    {
        self::assertNull((new JsonApiRoute('default', isAtomic: true))->target());
    }

    public function test_the_deprecated_renderer_constant_is_the_marker(): void
    {
        self::assertSame(JsonApiRoute::MARKER, JsonApiExceptionRenderer::ROUTE_MARKER);
    }

    private function routedTo(Route $route): Request
    {
        $request = Request::create('/' . $route->uri());
        $route->bind($request);
        $request->setRouteResolver(static fn(): Route => $route);

        return $request;
    }
}
