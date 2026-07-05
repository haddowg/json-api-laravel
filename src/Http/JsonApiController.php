<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApi\Atomic\AtomicOperationsParser;
use haddowg\JsonApi\Exception\ApplicationError;
use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Negotiation\RequestValidator;
use haddowg\JsonApi\Operation\AtomicOperationsOperation;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationFactory;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionRegistry;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Operation\TargetResolver;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Http\Request;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single invokable controller every JSON:API route points at. It collapses the Symfony
 * bundle's request + view kernel listeners into one action (PLAN decision 5):
 *  1. resolve the {@see \haddowg\JsonApi\Operation\Target} from the matched route via the
 *     {@see TargetResolver} (a batch/action route carries the marker defaults instead);
 *  2. pick the server by the `_jsonapi_server` route default;
 *  3. convert the Laravel request to a PSR-7 request and wrap it in core's
 *     {@see JsonApiRequest};
 *  4. run content negotiation + query-parameter validation, plus the write body's structure
 *     for a mutating verb;
 *  5. build the matching operation (a CRUD op via {@see OperationFactory}, a
 *     {@see CustomActionOperation} for an `-actions` route, or an
 *     {@see AtomicOperationsOperation} for the `/operations` batch) and call
 *     `Server::dispatch()`;
 *  6. render the returned response value object to PSR-7, then bridge it back.
 *
 * Errors are NOT caught here: any core JSON:API exception propagates to Laravel's exception
 * handler, where the route-scoped {@see \haddowg\JsonApiLaravel\Exception\JsonApiExceptionRenderer}
 * renders it — so the error path is owned in exactly one place.
 */
final class JsonApiController
{
    /**
     * The route default marking the per-server Atomic Operations endpoint (`POST {path}`):
     * the route carries NO `_jsonapi_type` (the batch has no single primary resource), so the
     * controller branches on this marker.
     */
    public const string ATOMIC_ATTRIBUTE = '_jsonapi_atomic';

    /**
     * The route default carrying a custom action's name (the `{action}` segment).
     */
    public const string ACTION_ATTRIBUTE = '_jsonapi_action';

    /**
     * The route default carrying a custom action's {@see ActionScope} case name, so the
     * controller and registry agree on the resource / collection scope without re-deriving it.
     */
    public const string ACTION_SCOPE_ATTRIBUTE = '_jsonapi_action_scope';

    public function __construct(
        private readonly ServerRegistry $servers,
        private readonly TargetResolver $targetResolver,
        private readonly PsrHttpFactory $psrHttpFactory,
        private readonly HttpFoundationFactory $httpFoundationFactory,
        private readonly ?ActionRegistry $actions = null,
        private readonly OperationFactory $operationFactory = new OperationFactory(),
        // Stamps `links.describedby` onto every response, pointing at the served OpenAPI
        // document (PLAN decision 11, bundle ADR 0105). Null on a stripped-down programmatic
        // wiring → no describedby link is added.
        private readonly ?DescribedbyStamper $describedby = null,
    ) {}

    public function __invoke(Request $request): Response
    {
        $route = $request->route();
        /** @var array<string, mixed> $defaults */
        $defaults = \is_object($route) && \property_exists($route, 'defaults') ? $route->defaults : [];

        // An Atomic Operations batch route carries no _jsonapi_type — branch on its marker
        // BEFORE the CRUD/action target resolution.
        if (($defaults[self::ATOMIC_ATTRIBUTE] ?? null) === true) {
            return $this->handleAtomic($defaults, $request);
        }

        $target = $this->targetResolver->resolveFromRequest($request);
        if ($target === null) {
            // The route matched a JSON:API endpoint but carried no _jsonapi_type — a wiring
            // fault, not a client error. Surface it as a 500 error document.
            throw new ApplicationError();
        }

        $serverName = $defaults[TargetResolver::SERVER_ATTRIBUTE] ?? null;
        $serverName = \is_string($serverName) && $serverName !== '' ? $serverName : ServerRegistry::DEFAULT_SERVER;
        $server = $this->servers->get($serverName);

        // Stamp the resolved server name onto the request so the ActionInvoker (and any
        // request-aware collaborator) can key by it — the Laravel equivalent of the bundle's
        // `_jsonapi_server` request attribute.
        $jsonApiRequest = $this->jsonApiRequest($request)->withAttribute(TargetResolver::SERVER_ATTRIBUTE, $serverName);
        \assert($jsonApiRequest instanceof JsonApiRequestInterface);

        // A custom-action route carries `_jsonapi_action`; it parses its body per the action's
        // declared input mode (None/Document/Raw) and builds a CustomActionOperation.
        $actionName = $defaults[self::ACTION_ATTRIBUTE] ?? null;
        if (\is_string($actionName) && $actionName !== '') {
            $operation = $this->actionOperation($jsonApiRequest, $target, $server, $serverName, $actionName, $defaults);
        } else {
            $validator = new RequestValidator();
            $validator->negotiate($jsonApiRequest);
            $validator->validateQueryParams($jsonApiRequest);

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
        }

        $response = $server->dispatch($operation);
        if ($this->describedby !== null) {
            $response = $this->describedby->stamp($response, $serverName);
        }

        return $this->httpFoundationFactory->createResponse($response->toPsrResponse($server, $jsonApiRequest));
    }

    /**
     * Runs an Atomic Operations batch (`POST /operations`): pick the server, wrap the request,
     * negotiate the **atomic extension** (a {@see RequestValidator} configured with
     * {@see AtomicExtension::URI} as the sole supported extension, then a REQUIRE check that
     * the `ext` media-type parameter is present on BOTH `Content-Type` (else `415`) and
     * `Accept` (else `406`)), validate the JSON body, parse `atomic:operations` via core's
     * {@see AtomicOperationsParser} (structural failures → a `400`), build the
     * {@see AtomicOperationsOperation} and dispatch it (which fires serving once for the whole
     * batch and runs the handler's atomic arm).
     *
     * @param array<string, mixed> $defaults the matched route's defaults
     */
    private function handleAtomic(array $defaults, Request $request): Response
    {
        $serverName = $defaults[TargetResolver::SERVER_ATTRIBUTE] ?? null;
        $serverName = \is_string($serverName) && $serverName !== '' ? $serverName : ServerRegistry::DEFAULT_SERVER;
        $server = $this->servers->get($serverName);

        $jsonApiRequest = $this->jsonApiRequest($request)->withAttribute(TargetResolver::SERVER_ATTRIBUTE, $serverName);
        \assert($jsonApiRequest instanceof JsonApiRequestInterface);

        $validator = new RequestValidator(AtomicExtension::URI);
        $validator->negotiate($jsonApiRequest);
        $this->requireAtomicExtension($jsonApiRequest);

        $validator->validateQueryParams($jsonApiRequest);
        $validator->validateJsonBody($jsonApiRequest);

        $descriptors = (new AtomicOperationsParser())->parse($jsonApiRequest->getParsedBody());

        $operation = new AtomicOperationsOperation(
            $descriptors,
            QueryParameters::fromRequest($jsonApiRequest),
            new OperationContext($server, $jsonApiRequest),
        );

        $response = $server->dispatch($operation);
        if ($this->describedby !== null) {
            $response = $this->describedby->stamp($response, $serverName);
        }

        return $this->httpFoundationFactory->createResponse($response->toPsrResponse($server, $jsonApiRequest));
    }

    /**
     * Enforces the atomic extension's media-type contract: the atomic `ext` parameter MUST be
     * present on both the request `Content-Type` (else `415`) and the `Accept` (else `406`).
     * The base negotiation only rejects an *unsupported* ext; it does not require the atomic
     * ext to be present, so a plain `application/vnd.api+json` request to `/operations` would
     * otherwise be accepted.
     */
    private function requireAtomicExtension(JsonApiRequestInterface $request): void
    {
        if (!\in_array(AtomicExtension::URI, $request->getAppliedExtensions(), true)) {
            throw new MediaTypeUnsupported($request->getHeaderLine('content-type'));
        }

        if (!\in_array(AtomicExtension::URI, $request->getRequestedExtensions(), true)) {
            throw new MediaTypeUnacceptable($request->getHeaderLine('accept'));
        }
    }

    /**
     * Builds the {@see CustomActionOperation} for a custom-action route: it resolves the
     * action's input mode from the {@see ActionRegistry} descriptor and handles the request
     * body per mode — {@see ActionInput::None} reads no body, {@see ActionInput::Document}
     * validates it as a JSON:API document exactly as the CRUD write path,
     * {@see ActionInput::Raw} relaxes the request content-type assertion for a non-JSON:API
     * upload. An unknown action (no descriptor) carries no body and defers the `404` to the
     * invoker.
     *
     * @param array<string, mixed> $defaults the matched route's defaults
     */
    private function actionOperation(
        JsonApiRequestInterface $jsonApiRequest,
        Target $target,
        Server $server,
        string $serverName,
        string $actionName,
        array $defaults,
    ): CustomActionOperation {
        $scope = ($defaults[self::ACTION_SCOPE_ATTRIBUTE] ?? null) === ActionScope::Collection->name
            ? ActionScope::Collection
            : ActionScope::Resource;
        $input = $this->actionInput($serverName, $target->type, $scope, $actionName);

        $validator = new RequestValidator();
        $validator->negotiate($jsonApiRequest, requireJsonApiContentType: $input !== ActionInput::Raw);
        $validator->validateQueryParams($jsonApiRequest);

        // Only a Document-input action reads + validates a JSON:API body; None/Raw carry no
        // document, so the operation body stays null.
        $body = null;
        if ($input === ActionInput::Document) {
            $validator->validateJsonBody($jsonApiRequest);
            $validator->validateTopLevelMembers($jsonApiRequest);
            $body = $jsonApiRequest;
        }

        return new CustomActionOperation(
            $target,
            QueryParameters::fromRequest($jsonApiRequest),
            new OperationContext($server, $jsonApiRequest),
            $actionName,
            \strtoupper($jsonApiRequest->getMethod()),
            $body,
        );
    }

    /**
     * The declared input mode for the addressed action, read from its {@see ActionRegistry}
     * descriptor; {@see ActionInput::None} when the registry is absent or no action matches
     * (the invoker renders the `404`, so the body is not read).
     */
    private function actionInput(string $serverName, string $type, ActionScope $scope, string $action): ActionInput
    {
        if ($this->actions === null) {
            return ActionInput::None;
        }

        $descriptor = $this->actions->descriptorFor($serverName, $type, $scope, $action);

        return $descriptor === null ? ActionInput::None : $descriptor->input;
    }

    /**
     * Wraps the Laravel request as core's {@see JsonApiRequest} (the idempotent guard core
     * uses everywhere).
     */
    private function jsonApiRequest(Request $request): JsonApiRequestInterface
    {
        $psrRequest = $this->psrHttpFactory->createRequest($request);

        return $psrRequest instanceof JsonApiRequestInterface ? $psrRequest : new JsonApiRequest($psrRequest);
    }
}
