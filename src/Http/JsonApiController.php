<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use haddowg\JsonApi\Exception\ApplicationError;
use haddowg\JsonApi\Negotiation\RequestValidator;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationFactory;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApiLaravel\Operation\TargetResolver;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Http\Request;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single invokable controller every JSON:API route points at. It collapses the
 * Symfony bundle's request + view kernel listeners into one action (PLAN decision 5):
 *  1. resolve the {@see \haddowg\JsonApi\Operation\Target} from the matched route via
 *     the {@see TargetResolver};
 *  2. pick the server by the `_jsonapi_server` route default;
 *  3. convert the Laravel request to a PSR-7 request and wrap it in core's
 *     {@see JsonApiRequest} (the idempotent guard core uses everywhere);
 *  4. run content negotiation + query-parameter validation via core's
 *     {@see RequestValidator}, plus — for a mutating verb (POST/PATCH) — the write
 *     body's JSON well-formedness + top-level-member structure;
 *  5. build the matching operation via core's {@see OperationFactory} and call
 *     `Server::dispatch()`;
 *  6. render the returned response value object to PSR-7 via the serializer-free
 *     `toPsrResponse()` seam, then bridge it back to an HttpFoundation response.
 *
 * Errors are NOT caught here: a negotiation failure, an unrecognized query family
 * (thrown from `Server::dispatch()` under strict mode), or any core JSON:API exception
 * propagates to Laravel's exception handler, where the route-scoped
 * {@see \haddowg\JsonApiLaravel\Exception\JsonApiExceptionRenderer} renders it — so the
 * error path is owned in exactly one place.
 */
final class JsonApiController
{
    public function __construct(
        private readonly ServerRegistry $servers,
        private readonly TargetResolver $targetResolver,
        private readonly PsrHttpFactory $psrHttpFactory,
        private readonly HttpFoundationFactory $httpFoundationFactory,
        private readonly OperationFactory $operationFactory = new OperationFactory(),
    ) {}

    public function __invoke(Request $request): Response
    {
        $target = $this->targetResolver->resolveFromRequest($request);
        if ($target === null) {
            // The route matched a JSON:API endpoint but carried no _jsonapi_type — a
            // wiring fault, not a client error. Surface it as a 500 error document.
            throw new ApplicationError();
        }

        $route = $request->route();
        $serverName = $route?->defaults[TargetResolver::SERVER_ATTRIBUTE] ?? null;
        $server = $this->servers->get(\is_string($serverName) ? $serverName : null);

        $psrRequest = $this->psrHttpFactory->createRequest($request);
        $jsonApiRequest = $psrRequest instanceof JsonApiRequestInterface
            ? $psrRequest
            : new JsonApiRequest($psrRequest);

        $validator = new RequestValidator();
        $validator->negotiate($jsonApiRequest);
        $validator->validateQueryParams($jsonApiRequest);

        // A mutating verb carries a body core's hydrator will read, so it is validated
        // up front — the belt core's RequestBodyParsingMiddleware would run, called
        // directly, matching the bundle's RequestListener order (negotiate → query →
        // JSON body → top-level members → dispatch). POST/PATCH always carry a resource
        // document; a resource DELETE carries no body. `validateJsonBody()` gates
        // malformed JSON (400 RequestBodyInvalidJson); `validateTopLevelMembers()` gates
        // the resource-document structure — a required `data`, and a stray `lid` on a
        // standalone write (400 LocalIdNotSupported). Relationship-endpoint bodies (Phase
        // 3) carry linkage whose `data` is legitimately null/[], so they skip the
        // required-top-level-member rule; there are no such endpoints yet.
        $method = \strtoupper($jsonApiRequest->getMethod());
        if ($method === 'POST' || $method === 'PATCH') {
            $validator->validateJsonBody($jsonApiRequest);
            if ($target->isRelationshipEndpoint === false) {
                $validator->validateTopLevelMembers($jsonApiRequest);
            }
        }

        $operation = $this->operationFactory->fromRequest(
            $jsonApiRequest,
            $target,
            new OperationContext($server, $jsonApiRequest),
        );

        $psrResponse = $server->dispatch($operation)->toPsrResponse($server, $jsonApiRequest);

        return $this->httpFoundationFactory->createResponse($psrResponse);
    }
}
