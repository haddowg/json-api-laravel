<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Exception;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApi\Exception\AccessDenied;
use haddowg\JsonApi\Exception\AuthenticationRequired;
use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\MethodNotAllowed;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\InternalServerError;
use haddowg\JsonApiLaravel\Http\JsonApiController;
use haddowg\JsonApiLaravel\Http\WireBody;
use haddowg\JsonApiLaravel\Http\WireQuery;
use haddowg\JsonApiLaravel\Routing\JsonApiRoute;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * The route-scoped renderable that owns every error on a JSON:API route (PLAN
 * decision 5) — the Laravel translation of the Symfony bundle's `ExceptionListener`.
 * Registered on Laravel's exception handler; it acts only when the matched route
 * carries the {@see JsonApiRoute::MARKER} default, so it never hijacks a non-JSON:API
 * route's error response.
 *
 * Mapping order ({@see toErrorResponse()}):
 *  1. a core {@see JsonApiExceptionInterface} renders through its own `getErrors()` /
 *     `getStatusCode()` — ALWAYS first, never overridden (the seam's invariant);
 *  2. the tagged {@see ExceptionMapperInterface} mappers, first non-null wins (the
 *     application seam);
 *  3. a `401` or `403`, whether Laravel's {@see AuthenticationException} /
 *     {@see AuthorizationException} or an {@see HttpExceptionInterface} with that status,
 *     renders through core's {@see AuthenticationRequired} / {@see AccessDenied}, so it
 *     carries the stable `AUTHENTICATION_REQUIRED` / `ACCESS_DENIED` code. Laravel's
 *     handler turns an `AuthorizationException` into a `403` HTTP exception before this
 *     renderer sees it, so the status check is the path a policy denial takes;
 *  4. any other {@see HttpExceptionInterface} (a `NotFoundHttpException`, …) maps to a
 *     status-keyed error;
 *  5. anything else → a `500` via core's shared {@see InternalServerError::for()},
 *     with `{exception, file, line, trace}` in the error `meta` gated on debug.
 *
 * A method the path does not accept is refused by the router before any route matches,
 * so the request has no route to carry the marker. The renderer then asks which route
 * each allowed method reaches on that path, and when one is a JSON:API route it renders
 * core's {@see MethodNotAllowed} under that route's server, with the `Allow` header. A
 * path none of whose routes is a JSON:API endpoint is left to Laravel.
 *
 * On the Atomic Operations route an error document declares the atomic extension on its
 * `Content-Type` `ext` parameter only when the request named it, on the `Content-Type`
 * `ext` or on any `Accept` range's `ext`. The headers are read directly, since the error
 * may come before negotiation ran: a `405`, or a `401` from the auth middleware. A request
 * that never named the extension gets a plain JSON:API error. A `415`
 * ({@see MediaTypeUnsupported}) or `406` ({@see MediaTypeUnacceptable}) never declares it,
 * since negotiation failed (ADR 0066, matching the bundle).
 *
 * Reporting/logging is deliberately NOT done here: unlike the Symfony bundle's
 * `ExceptionListener` (the only reporter in that kernel), Laravel's exception handler
 * has already run its `report()` phase — with full context — before render callbacks
 * fire, so logging again here would double every 500. This is the Laravel-idiomatic
 * split: the framework reports, this renderable only renders.
 */
final class JsonApiExceptionRenderer
{
    /**
     * @deprecated since 1.2, use {@see JsonApiRoute::MARKER}, or `$request->isJsonApi()` to test a request
     */
    public const string ROUTE_MARKER = JsonApiRoute::MARKER;

    /**
     * @param iterable<ExceptionMapperInterface> $mappers the application exception mappers, highest priority first
     */
    public function __construct(
        private readonly ServerRegistry $servers,
        private readonly PsrHttpFactory $psrHttpFactory,
        private readonly HttpFoundationFactory $httpFoundationFactory,
        private readonly bool $debug = false,
        private readonly iterable $mappers = [],
        private readonly ?Router $router = null,
    ) {}

    /**
     * Whether this renderer owns the error: the request is matched to a JSON:API route,
     * or `$throwable` is the router refusing a method on a path a JSON:API route serves.
     * Any other request is declined.
     */
    public function handles(Request $request, ?\Throwable $throwable = null): bool
    {
        return JsonApiRoute::matches($request)
            || ($throwable !== null && $this->jsonApiRouteForUnroutedMethod($request, $throwable) !== null);
    }

    /**
     * Renders `$throwable` to a spec-compliant JSON:API error response.
     */
    public function render(\Throwable $throwable, Request $request): Response
    {
        if (!JsonApiRoute::matches($request)) {
            $route = $this->jsonApiRouteForUnroutedMethod($request, $throwable);
            if ($route !== null) {
                // Resolve the server off a copy routed to the JSON:API route, so its mount
                // is recovered as it would be for a matched request.
                $routed = $request->duplicate();
                $routed->setRouteResolver(static fn(): Route => $route);
                $throwable = new MethodNotAllowed($request->getMethod(), self::allowedMethods($throwable), $throwable);
                $request = $routed;
            }
        }

        $server = $this->servers->forRequest($request);

        $psrRequest = WireBody::apply($this->psrHttpFactory->createRequest($request), $request)
            ->withQueryParams(WireQuery::params($request));

        $errorResponse = $this->toErrorResponse($throwable);
        if (self::isAtomicRoute($request)
            && !$throwable instanceof MediaTypeUnsupported
            && !$throwable instanceof MediaTypeUnacceptable
            && self::namesAtomicExtension($psrRequest)
        ) {
            $errorResponse = $errorResponse->withExtensions([AtomicExtension::URI]);
        }

        $psrResponse = $errorResponse->toPsrResponse($server, $psrRequest);

        return $this->httpFoundationFactory->createResponse($psrResponse);
    }

    private static function isAtomicRoute(Request $request): bool
    {
        $route = $request->route();

        return $route instanceof Route && ($route->defaults[JsonApiController::ATOMIC_ATTRIBUTE] ?? null) === true;
    }

    /**
     * Whether the request named the atomic extension on its `Content-Type` or `Accept`
     * `ext` parameter, read straight off the headers as negotiation may not have run.
     */
    private static function namesAtomicExtension(ServerRequestInterface $request): bool
    {
        $request = $request instanceof JsonApiRequestInterface ? $request : new JsonApiRequest($request);

        return \in_array(AtomicExtension::URI, $request->getAppliedExtensions(), true)
            || \in_array(AtomicExtension::URI, $request->getRequestedExtensions(), true);
    }

    /**
     * When the router refused the request's method, the JSON:API route one of the allowed
     * methods reaches on the same path, bound to the request, or `null` when none of them
     * is a JSON:API route.
     */
    private function jsonApiRouteForUnroutedMethod(Request $request, \Throwable $throwable): ?Route
    {
        if ($this->router === null || !$throwable instanceof MethodNotAllowedHttpException) {
            return null;
        }

        $routes = $this->router->getRoutes();
        foreach (self::allowedMethods($throwable) as $method) {
            foreach ($routes->get($method) as $route) {
                if (!$route->matches($request, includingMethod: false)) {
                    continue;
                }

                // The first route the method reaches is the one the router would pick.
                if (($route->defaults[JsonApiRoute::MARKER] ?? false) !== true) {
                    break;
                }

                return (clone $route)->bind($request);
            }
        }

        return null;
    }

    /**
     * The methods the router's `Allow` header says the path accepts.
     *
     * @return list<string>
     */
    private static function allowedMethods(\Throwable $throwable): array
    {
        $allow = $throwable instanceof HttpExceptionInterface ? ($throwable->getHeaders()['Allow'] ?? '') : '';
        if (!\is_string($allow)) {
            return [];
        }

        return \array_values(\array_filter(\array_map(\trim(...), \explode(',', $allow)), static fn(string $method): bool => $method !== ''));
    }

    private function toErrorResponse(\Throwable $throwable): ErrorResponse
    {
        // INVARIANT: a core JSON:API exception always renders natively, first, and is
        // never intercepted or overridden by a mapper.
        if ($throwable instanceof JsonApiExceptionInterface) {
            return ErrorResponse::fromException($throwable);
        }

        foreach ($this->mappers as $mapper) {
            $response = $mapper->map($throwable);
            if ($response !== null) {
                return $response;
            }
        }

        $status = match (true) {
            $throwable instanceof HttpExceptionInterface => $throwable->getStatusCode(),
            $throwable instanceof AuthorizationException => 403,
            $throwable instanceof AuthenticationException => 401,
            default => null,
        };

        // The detail stays debug-only, as for every other status error.
        $detail = $this->debug ? $throwable->getMessage() : '';

        if ($status === 401) {
            return ErrorResponse::fromException(new AuthenticationRequired($detail, $throwable));
        }

        if ($status === 403) {
            return ErrorResponse::fromException(new AccessDenied($detail, $throwable));
        }

        if ($status !== null) {
            return ErrorResponse::fromErrors($this->statusError($status, $throwable));
        }

        return ErrorResponse::fromErrors(InternalServerError::for($throwable, $this->debug));
    }

    private function statusError(int $status, \Throwable $throwable): Error
    {
        return new Error(
            status: (string) $status,
            title: HttpReasonPhrase::of($status),
            detail: $this->debug ? $throwable->getMessage() : '',
        );
    }
}
