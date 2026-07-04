<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Exception;

use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\InternalServerError;
use haddowg\JsonApiLaravel\Operation\TargetResolver;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The route-scoped renderable that owns every error on a JSON:API route (PLAN
 * decision 5) — the Laravel translation of the Symfony bundle's `ExceptionListener`.
 * Registered on Laravel's exception handler; it acts only when the matched route
 * carries the {@see self::ROUTE_MARKER} default, so it never hijacks a non-JSON:API
 * route's error response.
 *
 * Mapping order ({@see toErrorResponse()}):
 *  1. a core {@see JsonApiExceptionInterface} renders through its own `getErrors()` /
 *     `getStatusCode()` — ALWAYS first, never overridden (the seam's invariant);
 *  2. the tagged {@see ExceptionMapperInterface} mappers, first non-null wins (the
 *     application seam);
 *  3. a Laravel/Symfony {@see HttpExceptionInterface} (a `NotFoundHttpException`, …)
 *     maps to a status-keyed error;
 *  4. Laravel's {@see AuthorizationException} → `403`, {@see AuthenticationException} →
 *     `401`;
 *  5. anything else → a `500` via core's shared {@see InternalServerError::for()},
 *     with `{exception, file, line, trace}` in the error `meta` gated on debug.
 *
 * Reporting/logging is deliberately NOT done here: unlike the Symfony bundle's
 * `ExceptionListener` (the only reporter in that kernel), Laravel's exception handler
 * has already run its `report()` phase — with full context — before render callbacks
 * fire, so logging again here would double every 500. This is the Laravel-idiomatic
 * split: the framework reports, this renderable only renders.
 */
final class JsonApiExceptionRenderer
{
    public const string ROUTE_MARKER = '_jsonapi';

    /**
     * @param iterable<ExceptionMapperInterface> $mappers the application exception mappers, highest priority first
     */
    public function __construct(
        private readonly ServerRegistry $servers,
        private readonly PsrHttpFactory $psrHttpFactory,
        private readonly HttpFoundationFactory $httpFoundationFactory,
        private readonly bool $debug = false,
        private readonly iterable $mappers = [],
    ) {}

    /**
     * Whether the request is matched to a JSON:API route (so this renderer owns its
     * errors). A request with no matched route, or a non-JSON:API route, is declined.
     */
    public function handles(Request $request): bool
    {
        $route = $request->route();

        return $route !== null && ($route->defaults[self::ROUTE_MARKER] ?? false) === true;
    }

    /**
     * Renders `$throwable` to a spec-compliant JSON:API error response.
     */
    public function render(\Throwable $throwable, Request $request): Response
    {
        $route = $request->route();
        $serverName = $route?->defaults[TargetResolver::SERVER_ATTRIBUTE] ?? null;
        $server = $this->servers->get(\is_string($serverName) ? $serverName : null);

        $psrRequest = $this->psrHttpFactory->createRequest($request);

        $psrResponse = $this->toErrorResponse($throwable)->toPsrResponse($server, $psrRequest);

        return $this->httpFoundationFactory->createResponse($psrResponse);
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

        if ($throwable instanceof HttpExceptionInterface) {
            return ErrorResponse::fromErrors($this->statusError($throwable->getStatusCode(), $throwable));
        }

        if ($throwable instanceof AuthorizationException) {
            return ErrorResponse::fromErrors($this->statusError(403, $throwable));
        }

        if ($throwable instanceof AuthenticationException) {
            return ErrorResponse::fromErrors($this->statusError(401, $throwable));
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
