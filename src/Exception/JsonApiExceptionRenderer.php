<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Exception;

use haddowg\JsonApi\Exception\AccessDenied;
use haddowg\JsonApi\Exception\AuthenticationRequired;
use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\InternalServerError;
use haddowg\JsonApiLaravel\Http\WireBody;
use haddowg\JsonApiLaravel\Http\WireQuery;
use haddowg\JsonApiLaravel\Routing\JsonApiRoute;
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
    ) {}

    /**
     * Whether the request is matched to a JSON:API route (so this renderer owns its
     * errors). A request with no matched route, or a non-JSON:API route, is declined.
     */
    public function handles(Request $request): bool
    {
        return JsonApiRoute::matches($request);
    }

    /**
     * Renders `$throwable` to a spec-compliant JSON:API error response.
     */
    public function render(\Throwable $throwable, Request $request): Response
    {
        $server = $this->servers->forRequest($request);

        $psrRequest = WireBody::apply($this->psrHttpFactory->createRequest($request), $request)
            ->withQueryParams(WireQuery::params($request));

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
