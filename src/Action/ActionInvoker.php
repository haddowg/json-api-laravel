<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Authorization\Authorizer;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\Event\AfterActionEvent;
use haddowg\JsonApiLaravel\Event\BeforeActionEvent;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use haddowg\JsonApiLaravel\Server\TypeMetadataResolver;
use haddowg\JsonApiLaravel\Validation\ResourceValidator;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Invokes a custom, non-CRUD action — the Laravel twin of the bundle's
 * `Action\ActionInvoker`. The single {@see \haddowg\JsonApiLaravel\Operation\CrudOperationHandler}
 * delegates its {@see CustomActionOperation} arm here when this collaborator is wired
 * (`null` otherwise → the handler renders a `404`).
 *
 * `invoke()` resolves the {@see ActionDescriptor} + {@see ActionHandlerInterface} from the
 * {@see ActionRegistry} by the composite key `(server, type, scope, action)` (a `404` when
 * none); for a resource-scope action it fetches the entity through the type's DataProvider
 * (a `404` when absent); for a {@see ActionInput::Document} action it resolves, validates
 * (through the always-on validation bridge) and hydrates the request document into an
 * `inputType` instance; it then enforces the action's declared Gate `ability`
 * (PLAN decision 12) against the subject — validate-then-authorize, so a `422` precedes a
 * `403` exactly as the CRUD arms — builds the {@see ActionContext}, calls the handler, and
 * returns the handler's response value object.
 *
 * The request-wide serving gate and strict-query validation are inherited for free from
 * `Server::dispatch()` (the operation is a first-class JSON:API operation), so this invoker
 * owns only the per-action concerns.
 */
final readonly class ActionInvoker
{
    public function __construct(
        private ActionRegistry $registry,
        private DataProviderRegistry $providers,
        private DataPersisterRegistry $persisters,
        private TypeMetadataResolver $types,
        private ResourceValidator $validator,
        private Authorizer $authorizer,
        // The lifecycle-event dispatcher (PLAN decision 10): a custom action fires a
        // Before/AfterActionEvent around its handler. Null on a stripped-down
        // programmatic wiring → the dispatches are no-ops.
        private ?Dispatcher $dispatcher = null,
    ) {}

    public function invoke(CustomActionOperation $operation): DataResponse|MetaResponse|NoContentResponse|AcceptedResponse|SeeOtherResponse|ErrorResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $target = $operation->target();
        $type = $target->type;
        $scope = $target->hasId() ? ActionScope::Resource : ActionScope::Collection;

        $descriptor = $this->registry->descriptorFor($this->serverName($operation), $type, $scope, $operation->action());
        if ($descriptor === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $handler = $this->registry->handlerFor($descriptor);

        // Resource scope: resolve the {id} to an entity before the handler runs; a missing
        // entity is a 404, exactly as the CRUD read/update/delete arms.
        $entity = null;
        if ($scope === ActionScope::Resource) {
            $id = $target->id;
            $entity = $id !== null ? $this->providers->forType($type)->fetchOne($type, $id) : null;
            if ($entity === null) {
                return ErrorResponse::fromException(new ResourceNotFound());
            }
        }

        // Document input: validate then hydrate the request document into a fresh inputType
        // instance (None/Raw carry no document, so input stays null).
        $input = null;
        if ($descriptor->input === ActionInput::Document) {
            $body = $operation->body();
            if ($body === null) {
                return ErrorResponse::fromException(new ResourceNotFound());
            }

            $input = $this->resolveAndHydrateInput($server, $descriptor, $handler, $body);
        }

        // The before-action lifecycle event (PLAN decision 10): fired after entity
        // resolution + input validation and before the ability gate + handler. A listener
        // that throws a JsonApiException aborts. The subject is the resolved entity
        // (resource scope) or null (collection scope); the declared ability rides along.
        $this->dispatcher?->dispatch(new BeforeActionEvent($type, $operation->action(), $entity, $descriptor->ability));

        // The per-action ability gate (PLAN decision 12): enforced AFTER input validation so
        // a 422 precedes a 403 (the CRUD ordering). The subject is the resolved entity
        // (resource scope) or null (collection scope → the resource-class token). A null
        // ability is an unsecured action.
        if ($descriptor->ability !== null) {
            $this->authorizer->authorizeAction($type, $descriptor->ability, $entity);
        }

        $context = new ActionContext(
            $server,
            $descriptor,
            $entity,
            $input,
            $this->request($operation),
            $operation->queryParameters(),
        );

        $response = $handler->handle($context);

        // The after-action lifecycle event, for symmetry with the CRUD after-hooks. A
        // custom action is never part of an Atomic Operations batch, so it always fires
        // inline (no post-commit deferral).
        $this->dispatcher?->dispatch(new AfterActionEvent($type, $operation->action(), $entity));

        return $response;
    }

    /**
     * Resolves the blank input instance, runs the validation bridge against the
     * `inputType`, then hydrates the request body onto it — mirroring the
     * CrudOperationHandler's validate-then-hydrate create idiom. The blank instance is
     * supplied by the handler when it implements {@see ActionInputFactoryInterface} (a
     * bespoke command DTO with no persister), else by the `inputType` persister's
     * `instantiate()` (the common case where `inputType` defaults to the mount type).
     */
    private function resolveAndHydrateInput(
        Server $server,
        ActionDescriptor $descriptor,
        ActionHandlerInterface $handler,
        JsonApiRequestInterface $body,
    ): object {
        $inputType = $descriptor->inputType;

        $instance = $handler instanceof ActionInputFactoryInterface
            ? $handler->newInput($body)
            : $this->persisters->forType($inputType)->instantiate($inputType);

        $this->validate($server, $inputType, $body, $instance);

        $hydrated = $server->hydratorFor($inputType)->hydrate($body, $instance);
        \assert(\is_object($hydrated));

        return $hydrated;
    }

    /**
     * Runs the always-on validation bridge over the action's request document against the
     * `inputType`'s constraints (the create context — a fresh input instance is being
     * built), when the `inputType` has a resource declaring constraints. A bare
     * serializer/hydrator pair declares none, so there is nothing to validate — exactly the
     * CrudOperationHandler idiom.
     */
    private function validate(Server $server, string $inputType, JsonApiRequestInterface $body, object $instance): void
    {
        $resource = $this->types->resourceFor($server, $inputType);
        if ($resource === null) {
            return;
        }

        $this->validator->validate($resource, $body, true, subject: $instance);
    }

    /**
     * The current JSON:API request: the action's parsed body when present (Document mode),
     * else the originating request off the operation context.
     */
    private function request(CustomActionOperation $operation): JsonApiRequestInterface
    {
        $body = $operation->body();
        if ($body !== null) {
            return $body;
        }

        $request = $operation->context()->httpRequest();
        \assert($request instanceof JsonApiRequestInterface);

        return $request;
    }

    /**
     * The name of the server the action dispatched on, read from the `_jsonapi_server`
     * request attribute the controller stamps (the same name the route registrar keyed the
     * descriptor with), defaulting to the implicit `default` server.
     */
    private function serverName(CustomActionOperation $operation): string
    {
        $request = $operation->body() ?? $operation->context()->httpRequest();
        $name = $request?->getAttribute('_jsonapi_server');

        return \is_string($name) && $name !== '' ? $name : ServerRegistry::DEFAULT_SERVER;
    }
}
