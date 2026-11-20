<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Negotiation\AcceptNegotiator;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Request\MediaType;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\ForeignKey;
use haddowg\JsonApi\Resource\Field\GuardsMembershipInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\FilterMatcher;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApi\Resource\WrittenMeta;
use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Authorization\Authorizer;
use haddowg\JsonApiLaravel\DataPersister\AfterCommit;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataPersister\WriteTransaction;
use haddowg\JsonApiLaravel\DataPersister\WriteTransactionContext;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\FetchesTrashed;
use haddowg\JsonApiLaravel\DataProvider\FilterPaths;
use haddowg\JsonApiLaravel\DataProvider\ForeignKeyReads;
use haddowg\JsonApiLaravel\DataProvider\ScopedRelationshipWrites;
use haddowg\JsonApiLaravel\Event\AfterActionEvent;
use haddowg\JsonApiLaravel\Event\BeforeActionEvent;
use haddowg\JsonApiLaravel\Operation\ForeignKeyWrites;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApiLaravel\Operation\RelationFillHook;
use haddowg\JsonApiLaravel\Operation\RequestMetaResolver;
use haddowg\JsonApiLaravel\Serializer\RequestScopedRelationshipLinkage;
use haddowg\JsonApiLaravel\Server\ServerNames;
use haddowg\JsonApiLaravel\Server\TypeMetadataResolver;
use haddowg\JsonApiLaravel\Validation\FilterValueValidator;
use haddowg\JsonApiLaravel\Validation\ReadOnlyAttributes;
use haddowg\JsonApiLaravel\Validation\ResourceValidator;
use haddowg\JsonApiLaravel\Validation\ValidationFailed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

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
 * against the subject — validate-then-authorize, so a `422` precedes a
 * `403` exactly as the CRUD arms — builds the {@see ActionContext}, calls the handler, and
 * returns the handler's response value object.
 *
 * A collection action that takes its type's filters has them checked as the collection fetch
 * checks them, before the handler runs: an undeclared or untaken key is a `400`, a bad value
 * is the {@see FilterValueValidator}'s `400`, and the type's `viewAny` gate runs as well as
 * the action's own ability. The handler reads the rows through {@see ActionContext::filtered()}.
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
        // The lifecycle-event dispatcher: a custom action fires a
        // Before/AfterActionEvent around its handler. Null on a stripped-down
        // programmatic wiring → the dispatches are no-ops.
        private ?Dispatcher $dispatcher = null,
        private ?ServerNames $serverNames = null,
        private ?FilterValueValidator $filterValidator = null,
        private ?WriteTransactionContext $txContext = null,
        private ?AfterCommit $afterCommit = null,
        // The linkage holder whose identified members the scoped-write check reads, as the
        // CRUD handler's does.
        private ?RequestScopedRelationshipLinkage $relationshipLinkage = null,
        // The declared request meta of each action and the resource meta of its input type.
        private ?RequestMetaResolver $requestMeta = null,
    ) {}

    public function invoke(CustomActionOperation $operation): DataResponse|MetaResponse|NoContentResponse|AcceptedResponse|SeeOtherResponse|ErrorResponse|RawResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $target = $operation->target();
        $type = $target->type;
        $scope = $target->addressesResource() ? ActionScope::Resource : ActionScope::Collection;

        $serverName = $this->serverName($operation);
        $descriptor = $this->registry->descriptorFor($serverName, $type, $scope, $operation->action());
        if ($descriptor === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        // The response media type is picked from the declared responses before anything
        // runs, so an `Accept` the action cannot answer is a 406 up front. An action that
        // answers with a URL is never refused: it can always send its 303.
        $responseMediaType = AcceptNegotiator::negotiateAction(
            $this->request($operation)->getHeaderLine('accept'),
            $descriptor->declaredResponses(),
        ) ?? 'application/vnd.api+json';

        $handler = $this->registry->handlerFor($descriptor);

        // Resource scope: resolve the {id} to an entity before the handler runs; a missing
        // entity is a 404, exactly as the CRUD read/update/delete arms.
        $entity = null;
        if ($scope === ActionScope::Resource) {
            $id = $target->id;
            $entity = $id !== null ? $this->fetchTarget($type, $id, $descriptor) : null;
            if ($entity === null) {
                return ErrorResponse::fromException(new ResourceNotFound());
            }
        }

        // The call's top-level meta, and a document's `data.meta`, are validated against
        // what the handler and the input type declare, and their typed values ride the
        // request the hydrator and the handler read.
        $written = $this->writtenMeta($server, $descriptor, $handler, $operation);

        // Document input: validate then hydrate the request document into a fresh inputType
        // instance (None/Raw carry no document, so input stays null).
        $input = null;
        if ($this->inputMode($operation, $descriptor) === ActionInput::Document) {
            $body = $operation->body();
            if ($body === null) {
                return ErrorResponse::fromException(new ResourceNotFound());
            }

            $input = $this->resolveAndHydrateInput($server, $descriptor, $handler, $written->on($body), $entity);
        }

        // A collection action taking its type's filters: the requested keys and values are
        // checked as the collection fetch checks them, so a 400 precedes any 403.
        $filters = $scope === ActionScope::Collection ? $this->effectiveFilters($server, $type, $descriptor) : [];
        $requestedFilter = $operation->queryParameters()->filter;
        $paths = $filters !== [] ? FilterPaths::of($server, $server->resourceFor($type)) : null;
        if ($filters !== []) {
            $this->validateFilter($requestedFilter, $filters, $this->request($operation), $paths);
        }

        $context = new ActionContext(
            $server,
            $descriptor,
            $entity,
            $input,
            $written->on($this->request($operation)),
            $operation->queryParameters(),
            $responseMediaType,
            $filters === [] ? null : fn(): FilteredCollection => new FilteredCollection(
                $this->providers->forType($type),
                $type,
                new CollectionCriteria(
                    new QueryParameters(fields: [], includes: [], sort: [], filter: $requestedFilter, pagination: []),
                    $filters,
                    request: $this->request($operation),
                    paths: $paths,
                ),
            ),
            $this->afterCommit,
        );

        // An action called with a writing method runs from its before event to its after event
        // in one transaction on its type's persister, so a write it made is undone when
        // anything up to the response throws. Every action runs in an after-commit scope, so
        // the callbacks it registers run once it commits, or once it returns without a
        // transaction.
        $persister = $this->writes($operation) ? $this->persisterFor($descriptor) : null;

        return WriteTransaction::run($persister, $this->txContext, function () use ($type, $operation, $entity, $descriptor, $filters, $serverName, $handler, $context, $responseMediaType): DataResponse|MetaResponse|NoContentResponse|AcceptedResponse|SeeOtherResponse|ErrorResponse|RawResponse {
            if ($filters !== []) {
                // Reading the type's filtered rows needs what listing the collection needs.
                $listSubject = $this->persisters->supportsType($type) ? $this->persisters->forType($type)->instantiate($type) : null;
                $this->authorizer->authorize($type, Operation::FetchCollection, $listSubject, $serverName);
            }

            // The per-action ability gate: enforced AFTER input validation so
            // a 422 precedes a 403 (the CRUD ordering). The subject is the resolved entity
            // (resource scope) or null (collection scope → the resource-class token). A null
            // ability is an unsecured action.
            if ($descriptor->ability !== null) {
                $this->authorizer->authorizeAction($type, $descriptor->ability, $entity, $serverName);
            }

            // The before-action lifecycle event: fired after entity resolution, input
            // validation and the gates above, so a caller they refuse reaches no listener, and
            // before the handler. A listener that throws a JsonApiException aborts. The subject
            // is the resolved entity (resource scope) or null (collection scope); the declared
            // ability rides along.
            $this->dispatcher?->dispatch(new BeforeActionEvent($type, $operation->action(), $entity, $descriptor->ability));

            $response = $handler->handle($context);

            if ($response instanceof RawResponse && MediaType::essence($response->contentType()) !== $responseMediaType) {
                throw new \LogicException(\sprintf(
                    'The JSON:API action "%s" on type "%s" answered with a "%s" body, but the request negotiated "%s".',
                    $operation->action(),
                    $type,
                    $response->contentType(),
                    $responseMediaType,
                ));
            }

            // The after-action lifecycle event, for symmetry with the CRUD after-hooks. A
            // custom action is never part of an Atomic Operations batch, so it always fires
            // inline (no post-commit deferral), inside the action's transaction.
            $this->dispatcher?->dispatch(new AfterActionEvent($type, $operation->action(), $entity));

            return $response;
        }, $this->afterCommit);
    }

    /**
     * Validates the call's top-level `meta` against the members the handler declares for its
     * method, and a document input's `data.meta` against its input type's `resourceMeta()`,
     * in the update context for a `PATCH` and the create context otherwise. A raw body
     * carries no JSON:API `meta`.
     *
     * @throws ValidationFailed
     */
    private function writtenMeta(Server $server, ActionDescriptor $descriptor, ActionHandlerInterface $handler, CustomActionOperation $operation): WrittenMeta
    {
        $mode = $this->inputMode($operation, $descriptor);
        if ($this->requestMeta === null || $mode === ActionInput::Raw) {
            return new WrittenMeta();
        }

        $request = $this->request($operation);
        $shape = $this->requestMeta->forAction($handler, $request->getMethod(), RequestMetaResolver::actionOwner($descriptor->path, $descriptor->type));
        $document = $shape->isEmpty() ? null : RequestMetaResolver::documentMetaOf($request);
        $errors = $this->validator->metaErrors($shape, $document, '/meta', true, $request);

        $resourceShape = null;
        $resource = null;
        // A PATCH writes its input in the update context, as its attributes validate.
        $creating = \strtoupper($request->getMethod()) !== 'PATCH';
        if ($mode === ActionInput::Document && $descriptor->inputType !== '') {
            $resource = RequestMetaResolver::resourceMetaOf($request);
            $resourceShape = $this->requestMeta->forResource($server, $descriptor->inputType, MetaContext::write($creating ? OperationType::Create : OperationType::Update));
            $errors = [...$errors, ...$this->validator->metaErrors($resourceShape, $resource, '/data/meta', $creating, $request)];
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        // An input type declaring no resource meta for the write leaves the typed data.meta
        // empty; the raw meta stays on the request.
        return new WrittenMeta(
            \is_array($document) ? $shape->values($document, true, $request) : [],
            $resourceShape !== null && !$resourceShape->isEmpty() && \is_array($resource) ? $resourceShape->values($resource, $creating, $request) : [],
        );
    }

    /**
     * Whether the action was called with a method that writes: POST, PATCH, PUT or DELETE.
     */
    private function writes(CustomActionOperation $operation): bool
    {
        return \in_array(\strtoupper($this->request($operation)->getMethod()), ['POST', 'PATCH', 'PUT', 'DELETE'], true);
    }

    /**
     * The persister whose transaction a writing action runs in: its mount type's, else, for
     * a mount type with no persister, the persister of the type its document reads in.
     * Null when neither has one, and the action runs as it is.
     */
    private function persisterFor(ActionDescriptor $descriptor): ?DataPersisterInterface
    {
        foreach ([$descriptor->type, $descriptor->inputType] as $type) {
            if ($type !== '' && $this->persisters->supportsType($type)) {
                return $this->persisters->forType($type);
            }
        }

        return null;
    }

    /**
     * The mount type's filters the action takes, in the type's order, by the rule core's
     * `ActionDeclarations::filters()` applies for the OpenAPI document: all of them for
     * `true`, the named ones for a list, none otherwise.
     *
     * @return list<FilterInterface>
     */
    private function effectiveFilters(Server $server, string $type, ActionDescriptor $descriptor): array
    {
        $declared = $descriptor->filters;
        if ($declared === false || $declared === [] || !$server->hasResourceFor($type)) {
            return [];
        }

        $filters = $server->resourceFor($type)->allFilters();

        return $declared === true
            ? $filters
            : \array_values(\array_filter($filters, static fn(FilterInterface $filter): bool => \in_array($filter->key(), $declared, true)));
    }

    /**
     * Rejects a requested key the action does not take, then the values its filters
     * reject. A key matches through core's {@see FilterMatcher}, so a filter family the
     * action takes answers its keys here as it does on the collection.
     *
     * @param array<string, mixed>  $requested
     * @param list<FilterInterface> $filters
     */
    private function validateFilter(array $requested, array $filters, JsonApiRequestInterface $request, ?FilterPaths $paths): void
    {
        foreach (\array_keys($requested) as $key) {
            if (FilterMatcher::match($filters, (string) $key, $request) === null) {
                throw new FilterParamUnrecognized((string) $key);
            }
        }

        $this->filterValidator?->validate($requested, $filters, $request, $paths);
    }

    /**
     * Resolves the resource-scope action's `{id}` to its target entity. A descriptor that
     * {@see ActionDescriptor::$resolvesTrashed} (the synthesized soft-delete restore/force-delete
     * actions) resolves through the trashed-inclusive fetch when the type's provider is
     * {@see FetchesTrashed} — so it can reach a soft-deleted target — while every other action
     * (and every provider that does not offer the capability) resolves through the ordinary,
     * trashed-excluding {@see \haddowg\JsonApiLaravel\DataProvider\DataProviderInterface::fetchOne()}.
     */
    private function fetchTarget(string $type, string $id, ActionDescriptor $descriptor): ?object
    {
        $provider = $this->providers->forType($type);

        if ($descriptor->resolvesTrashed && $provider instanceof FetchesTrashed) {
            return $provider->fetchOneWithTrashed($type, $id);
        }

        return $provider->fetchOne($type, $id);
    }

    /**
     * Resolves the blank input instance, runs the validation bridge against the
     * `inputType`, then hydrates the request body onto it — mirroring the
     * CrudOperationHandler's validate-then-hydrate create idiom. The blank instance is
     * supplied by the handler when it implements {@see ActionInputFactoryInterface} (a
     * bespoke command DTO with no persister), else by the `inputType` persister's
     * `instantiate()` (the common case where `inputType` defaults to the mount type).
     * `$entity` is the resource a resource-scope action was called on, `null` for a
     * collection action.
     */
    private function resolveAndHydrateInput(
        Server $server,
        ActionDescriptor $descriptor,
        ActionHandlerInterface $handler,
        JsonApiRequestInterface $body,
        ?object $entity,
    ): object {
        $inputType = $descriptor->inputType;

        $instance = $handler instanceof ActionInputFactoryInterface
            ? $handler->newInput($body)
            : $this->persisters->forType($inputType)->instantiate($inputType);

        $this->validate($server, $inputType, $body, $instance);

        if ($handler instanceof ActionInputFactoryInterface) {
            [$instance, $body] = $this->fillUnguarded($server, $inputType, $instance, $body);
        }

        $resource = $handler instanceof ActionInputFactoryInterface ? null : $this->types->resourceFor($server, $inputType);
        if ($resource !== null) {
            // The input's members are compared with the resource the action was called on when
            // the input reads as that resource's type, otherwise with the blank instance.
            $stored = $entity !== null && $inputType === $descriptor->type ? $entity : null;
            [$instance, $body] = $this->relateInput($server, $resource, $instance, $body, $stored);
        }

        $hydrator = $server->hydratorFor($inputType);
        $hydrated = $hydrator->hydrate($body, $instance);
        \assert(\is_object($hydrated));

        // A resource hands `data.meta` to its declared members' hooks as it hydrates; a
        // composed hydrator's type has them applied here.
        $meta = RequestMetaResolver::resourceMetaOf($body);
        if (!$hydrator instanceof AbstractResource && $this->requestMeta !== null && \is_array($meta) && $meta !== []) {
            $creating = \strtoupper($body->getMethod()) !== 'PATCH';
            /** @var array<string, mixed> $meta */
            $filled = $this->requestMeta->forResource($server, $inputType, MetaContext::write($creating ? OperationType::Create : OperationType::Update))->fill($hydrated, $meta, $creating, $body);
            $hydrated = \is_object($filled) ? $filled : $hydrated;
        }

        return $hydrated;
    }

    /**
     * Fills each to-many declared `cannotChangeMembership()` that a `PATCH` input names onto an
     * input from {@see ActionInputFactoryInterface}, as core's hydration would but without
     * comparing its members, and returns the document without them. The factory's object is
     * no stored resource, so the factory or the handler decides what a change of them means.
     *
     * @return array{object, JsonApiRequestInterface}
     */
    private function fillUnguarded(Server $server, string $inputType, object $instance, JsonApiRequestInterface $body): array
    {
        $resource = $body->getMethod() === 'PATCH' ? $this->types->resourceFor($server, $inputType) : null;
        /** @var array<string, mixed> $document */
        $document = (array) $body->getParsedBody();
        $data = $document['data'] ?? null;
        if ($resource === null || !\is_array($data) || !\is_array($data['relationships'] ?? null)) {
            return [$instance, $body];
        }

        $relationships = $data['relationships'];
        foreach (\array_keys($relationships) as $name) {
            $relation = $resource->relationNamed((string) $name);
            if ($relation === null || !$relation->isToMany() || !$relation instanceof GuardsMembershipInterface
                || $relation->isReadOnlyFor(false, $body) || !$body->hasToManyRelationship($relation->name())
                || $relation->allowsMembershipChangeFor($body, $instance)) {
                continue;
            }

            $filled = $relation->hydrateRelationship($instance, $body->getToManyRelationship($relation->name()));
            \assert(\is_object($filled));
            $instance = $filled;
            unset($relationships[$name]);
        }

        if (\count($relationships) === \count($data['relationships'])) {
            return [$instance, $body];
        }

        $data['relationships'] = $relationships;
        $document['data'] = $data;
        $stripped = $body->withParsedBody($document);
        \assert($stripped instanceof JsonApiRequestInterface);

        return [$instance, $stripped];
    }

    /**
     * Sets each relationship the input document names to the related models, as a write
     * of the type holds them, and returns the document without its relationships. Core's
     * hydration would assign the bare ids, as an attribute of a model. A model gets each
     * as a loaded relation, any other object through its accessor. A relation written by
     * its `fillUsing()` hook still gets the linkage, and a to-many that can't change its
     * members is checked first against the members `$stored` holds, the resource the
     * action was called on, or the blank input when it is `null`. A member that doesn't
     * exist is a `404`.
     *
     * @return array{object, JsonApiRequestInterface}
     */
    private function relateInput(Server $server, AbstractResource $resource, object $instance, JsonApiRequestInterface $body, ?object $stored): array
    {
        /** @var array<string, mixed> $document */
        $document = (array) $body->getParsedBody();
        $data = $document['data'] ?? null;
        if (!\is_array($data) || !\is_array($data['relationships'] ?? null) || $data['relationships'] === []) {
            return [$instance, $body];
        }

        $creating = $body->getMethod() === 'POST';
        foreach (\array_keys($data['relationships']) as $name) {
            $relation = $resource->relationNamed((string) $name);
            if ($relation === null || $relation->isReadOnlyFor($creating, $body)) {
                continue;
            }

            $linkage = $relation->isToMany()
                ? ($body->hasToManyRelationship($relation->name()) ? $body->getToManyRelationship($relation->name()) : null)
                : ($body->hasToOneRelationship($relation->name()) ? $body->getToOneRelationship($relation->name()) : null);
            if ($linkage === null) {
                continue;
            }

            if (!$creating && $linkage instanceof ToManyRelationship && $relation instanceof GuardsMembershipInterface
                && !$relation->allowsMembershipChangeFor($body, $stored ?? $instance)) {
                $this->guardMembership($server, $resource, $relation, $linkage, $body, $stored ?? $instance, $stored !== null);
            }

            if (RelationFillHook::declaredBy($relation)) {
                $instance = RelationFillHook::apply($relation, $instance, $linkage, Mode::Replace);

                continue;
            }

            $member = $relation->column() ?? $relation->name();
            if ($linkage instanceof ToOneRelationship) {
                $identifier = $linkage->resourceIdentifier;
                $related = $identifier?->id === null ? null : $this->related($identifier->type, $identifier->id);
                if ($instance instanceof Model) {
                    $instance->setRelation($member, $related);
                } else {
                    Accessor::set($instance, $member, $related);
                }

                continue;
            }

            $members = [];
            foreach ($linkage->resourceIdentifiers as $identifier) {
                if ($identifier->id !== null) {
                    $members[] = $this->related($identifier->type, $identifier->id);
                }
            }
            // A foreign-key to-many holds nothing on the model, so an input with nowhere to put
            // it leaves its linkage to the handler rather than gain a dynamic property.
            if (!$instance instanceof Model && ForeignKey::nameOf($relation) !== null && !self::holds($instance, $member)) {
                continue;
            }
            if ($instance instanceof Model) {
                $models = \array_values(\array_filter($members, static fn(object $related): bool => $related instanceof Model));
                $instance->setRelation($member, \count($models) === \count($members) ? new EloquentCollection($models) : new \Illuminate\Support\Collection($members));
            } else {
                Accessor::set($instance, $member, $members);
            }
        }

        unset($data['relationships']);
        $document['data'] = $data;
        $stripped = $body->withParsedBody($document);
        \assert($stripped instanceof JsonApiRequestInterface);

        return [$instance, $stripped];
    }

    /**
     * Whether `$instance` takes a value for `$member`: an array access, a declared property or
     * a setter.
     */
    private static function holds(object $instance, string $member): bool
    {
        return $instance instanceof \ArrayAccess
            || \property_exists($instance, $member)
            || \method_exists($instance, 'set' . \ucfirst($member));
    }

    /**
     * The members `$relation` holds on `$current`, as wire `[type, id]` pairs, read as the
     * update of a resource reads them: an identified `MorphToMany`'s stored pairs, otherwise
     * the relation's value read off a copy, so a relation an Eloquent model loads doesn't
     * stay on the resource the handler is given.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function currentMembers(Server $server, RelationInterface $relation, object $current, JsonApiRequestInterface $body): array
    {
        if ($relation instanceof MorphToMany && $relation->storesIdentifiers()) {
            return \array_map(
                static fn(array $pair): array => [$pair[0], self::wireId($server, $pair[0], $pair[1])],
                $relation->storedIdentifierList($current, $body),
            );
        }

        $members = [];
        foreach (\is_iterable($value = $relation->readValue(clone $current, $body)) ? $value : [] as $member) {
            if ($member instanceof ResourceIdentifier) {
                $members[] = [$member->type, (string) $member->id];
            } elseif (\is_object($member) && ($serializer = $relation->resolveSerializer($member, $server)) !== null) {
                $members[] = [$serializer->getType($member), $serializer->getId($member)];
            } elseif (\is_string($member) || \is_int($member)) {
                $members[] = [$relation->relatedTypes()[0] ?? '', (string) $member];
            }
        }

        return $members;
    }

    /**
     * Refuses a replacement that changes the members of a to-many declaring
     * `cannotChangeMembership()`, read as an update of the resource reads them: the rows its
     * foreign key serves for a relation declared with one, otherwise its members with the ones
     * the related type's scope hides kept in the linkage, so a client restating the members it
     * can see changes nothing. The blank input of an action called on no resource holds none.
     *
     * @throws \haddowg\JsonApi\Exception\MembershipChangeProhibited
     */
    private function guardMembership(Server $server, AbstractResource $resource, RelationInterface&GuardsMembershipInterface $relation, ToManyRelationship $linkage, JsonApiRequestInterface $body, object $current, bool $stored): void
    {
        if (!$stored) {
            $relation->assertMembershipUnchanged($body, $current, $this->currentMembers($server, $relation, $current, $body), $linkage);

            return;
        }

        $reads = new ForeignKeyReads($this->providers);
        if ($reads->reads($relation)) {
            $writes = new ForeignKeyWrites($this->providers, $this->persisters, $reads);
            $relation->assertMembershipUnchanged($body, $current, $writes->memberPairs($server, $resource::$type, $current, $relation, $body), $linkage);

            return;
        }

        $scoped = new ScopedRelationshipWrites($this->providers, $this->relationshipLinkage?->members());
        $relation->assertMembershipUnchanged(
            $body,
            $current,
            $this->currentMembers($server, $relation, $current, $body),
            $scoped->withHiddenMembers($current, $relation, $linkage, $server, $body),
        );
    }

    private static function wireId(Server $server, string $type, string|int $key): string
    {
        $serializer = $server->hasSerializerFor($type) ? $server->serializerFor($type) : null;
        if ($serializer instanceof AbstractResource) {
            foreach ($serializer->allFields() as $field) {
                if ($field instanceof Id) {
                    return $field->encoder()?->encode($key) ?? (string) $key;
                }
            }
        }

        return (string) $key;
    }

    private function related(string $type, string $id): object
    {
        if (!$this->providers->supportsType($type)) {
            throw new ResourceNotFound();
        }

        return $this->providers->forType($type)->fetchOne($type, $id) ?? throw new ResourceNotFound();
    }

    /**
     * Runs the always-on validation bridge over the action's request document against the
     * `inputType`'s constraints, when the `inputType` has a resource declaring constraints.
     * A bare serializer/hydrator pair declares none, so there is nothing to validate —
     * exactly the CrudOperationHandler idiom. A `PATCH` validates in the update context and
     * anything else in the create context, as the hydrator picks.
     */
    private function validate(Server $server, string $inputType, JsonApiRequestInterface $body, object $instance): void
    {
        $resource = $this->types->resourceFor($server, $inputType);
        if ($resource === null) {
            return;
        }

        $creating = $body->getMethod() !== 'PATCH';
        ReadOnlyAttributes::reject($server, $resource, $body, $creating);
        $this->validator->validate($resource, $body, $creating, subject: $instance);
    }

    /**
     * The mode the controller read the body in: the one its `accepts` entry names when the
     * action lists several, else the action's declared input.
     */
    private function inputMode(CustomActionOperation $operation, ActionDescriptor $descriptor): ActionInput
    {
        $mode = $this->request($operation)->getAttribute(ActionContext::INPUT_ATTRIBUTE);

        return \is_string($mode) && \defined(ActionInput::class . '::' . $mode) ? ActionInput::{$mode} : $descriptor->input;
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
     * descriptor with). A dispatch without it uses the server the operation carries, and
     * failing that the implicit `default` server.
     */
    private function serverName(CustomActionOperation $operation): string
    {
        $request = $operation->body() ?? $operation->context()->httpRequest();
        $request = $request instanceof JsonApiRequestInterface ? $request : null;

        return ($this->serverNames ?? new ServerNames())->resolve($request, $operation->context()->server);
    }
}
