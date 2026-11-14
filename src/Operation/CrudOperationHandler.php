<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Operation;

use haddowg\JsonApi\Atomic\AtomicLoop;
use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Collection\LinkRows\LinkRowQuery;
use haddowg\JsonApi\Collection\LinkRows\LinkRowQueryInterface;
use haddowg\JsonApi\Collection\LinkRows\LinkRowWritePlan;
use haddowg\JsonApi\Exception\AdditionProhibited;
use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Exception\FullReplacementProhibited;
use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Exception\RelationshipCountNotAllowed;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Exception\RelationshipTypeInappropriate;
use haddowg\JsonApi\Exception\RemovalProhibited;
use haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Operation\AddToRelationshipOperation;
use haddowg\JsonApi\Operation\AtomicOperationsOperation;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchRelationshipOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\RemoveFromRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PageInterface;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Request\RelatedQuery;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\DateTimeParser;
use haddowg\JsonApi\Resource\Field\AcceptsDefaultOutputTimezoneInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\GuardsMembershipInterface;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Positions;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\FilterMatcher;
use haddowg\JsonApi\Resource\Filter\SupportsSingular;
use haddowg\JsonApi\Resource\ResolvesCompletionRedirect;
use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApi\Response\AtomicResultsResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Schema\Relationship\RelationshipPagination;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\RequestBaseUri;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Action\ActionInvoker;
use haddowg\JsonApiLaravel\Atomic\AtomicLoopBackend;
use haddowg\JsonApiLaravel\Atomic\AtomicOperationsUnavailable;
use haddowg\JsonApiLaravel\Authorization\Authorizer;
use haddowg\JsonApiLaravel\Authorization\ViewableRows;
use haddowg\JsonApiLaravel\DataPersister\AcceptedForProcessing;
use haddowg\JsonApiLaravel\DataPersister\AsyncWriteNotAllowedInAtomicOperation;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataPersister\PositionColumn;
use haddowg\JsonApiLaravel\DataPersister\ReportsCurrentOwners;
use haddowg\JsonApiLaravel\DataPersister\TransactionalDataPersisterInterface;
use haddowg\JsonApiLaravel\DataPersister\WritesLinkRowsInterface;
use haddowg\JsonApiLaravel\DataPersister\WriteTransactionContext;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\NaiveDateTimeStorage;
use haddowg\JsonApiLaravel\DataProvider\FilterPaths;
use haddowg\JsonApiLaravel\DataProvider\IdentifiedMorphMembers;
use haddowg\JsonApiLaravel\DataProvider\IdentifiedMorphTargets;
use haddowg\JsonApiLaravel\DataProvider\Polymorphism;
use haddowg\JsonApiLaravel\DataProvider\QueriesPivotColumnsInterface;
use haddowg\JsonApiLaravel\DataProvider\RelatedIncludeBatcher;
use haddowg\JsonApiLaravel\DataProvider\RelationCountBatcher;
use haddowg\JsonApiLaravel\DataProvider\RelationCriteriaFactory;
use haddowg\JsonApiLaravel\DataProvider\RelationshipWindowBatcher;
use haddowg\JsonApiLaravel\DataProvider\ScopedPolymorphicMembers;
use haddowg\JsonApiLaravel\DataProvider\ScopedRelationshipWrites;
use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Event\AfterCreateEvent;
use haddowg\JsonApiLaravel\Event\AfterDeleteEvent;
use haddowg\JsonApiLaravel\Event\AfterFetchCollectionEvent;
use haddowg\JsonApiLaravel\Event\AfterFetchOneEvent;
use haddowg\JsonApiLaravel\Event\AfterRelationshipMutateEvent;
use haddowg\JsonApiLaravel\Event\AfterSaveEvent;
use haddowg\JsonApiLaravel\Event\AfterUpdateEvent;
use haddowg\JsonApiLaravel\Event\BeforeCreateEvent;
use haddowg\JsonApiLaravel\Event\BeforeDeleteEvent;
use haddowg\JsonApiLaravel\Event\BeforeFetchCollectionEvent;
use haddowg\JsonApiLaravel\Event\BeforeFetchRelatedEvent;
use haddowg\JsonApiLaravel\Event\BeforeFetchRelationshipEvent;
use haddowg\JsonApiLaravel\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiLaravel\Event\BeforeSaveEvent;
use haddowg\JsonApiLaravel\Event\BeforeUpdateEvent;
use haddowg\JsonApiLaravel\Serializer\BatchedRelationshipCount;
use haddowg\JsonApiLaravel\Serializer\PivotMetaSerializer;
use haddowg\JsonApiLaravel\Serializer\PivotParentSerializer;
use haddowg\JsonApiLaravel\Serializer\RequestScopedRelationshipCount;
use haddowg\JsonApiLaravel\Serializer\RequestScopedRelationshipLinkage;
use haddowg\JsonApiLaravel\Serializer\RequestScopedRelationshipPagination;
use haddowg\JsonApiLaravel\Serializer\WindowedRelationshipLinkage;
use haddowg\JsonApiLaravel\Serializer\WindowedRelationshipPagination;
use haddowg\JsonApiLaravel\Server\IdEncoderResolver;
use haddowg\JsonApiLaravel\Server\ServerNames;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use haddowg\JsonApiLaravel\Server\TypeMetadataResolver;
use haddowg\JsonApiLaravel\Validation\FilterValueValidator;
use haddowg\JsonApiLaravel\Validation\ReadOnlyAttributes;
use haddowg\JsonApiLaravel\Validation\ResourceValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Psr\Log\LoggerInterface;

/**
 * The single generic operation handler, wired via `Server::withHandler()` so
 * `Server::dispatch()` has a target — the Laravel twin of the Symfony bundle's
 * `CrudOperationHandler`. It drives the whole-resource read + write surface over the
 * per-type {@see \haddowg\JsonApiLaravel\DataProvider\DataProviderInterface} /
 * {@see \haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface} resolved from the
 * two registries.
 *
 * **Read** (`GET /{type}` and `GET /{type}/{id}`). A single fetch maps a missing resource
 * to a JSON:API `404` (the handler RETURNS an {@see ErrorResponse}, it does not throw). A
 * collection fetch resolves the resource's declared filter/sort vocabularies, default sort
 * and pagination strategy into a {@see CollectionCriteria}, asks the provider to execute
 * it, and renders the pagination matrix: a **singular** filter collapses to a zero-to-one
 * resource; a **cursor** (keyset) page renders through the paginator's `fromBoundaries()`
 * path carrying the provider-minted tokens; a **counted** page renders `meta.page.total` +
 * `links.last` and echoes `meta.total`; a **count-free** page renders self/first/prev/
 * next with `next` driven by `hasMore` and no total; a **fetch-all** (no paginator)
 * renders `meta.total` unconditionally.
 *
 * **Write** (`POST /{type}`, `PATCH /{type}/{id}`, `DELETE /{type}/{id}`). Create asks the
 * persister for a blank instance, lets core's per-type hydrator populate it from the
 * request document (allow-list hydration — an undeclared attribute is dropped; a wrong
 * `type` is core's `409`, a forbidden client `data.id` core's `403`), commits it through
 * the persister, and renders the created resource as `201` + a `Location` header equal to
 * the resource's own `links.self` (core's docs/links-and-meta.md). Update loads the target (a miss is a
 * `404`), hydrates the incoming partial onto it, commits, and renders `200`. Delete loads
 * the target (a miss is a `404`), removes it, and renders `204`.
 *
 * **Relationship reads**: {@see fetchRelated()} (`GET /{type}/{id}/{rel}`) and
 * {@see fetchRelationship()} (`GET …/relationships/{rel}`) render the related resource(s) /
 * linkage per cardinality (monomorphic + polymorphic to-one AND to-many), gated by the
 * parent's read policy; the {@see fetch()} arms grow the `?include` batch-eager-load
 * ({@see RelatedIncludeBatcher}) and `?withCount` count ({@see RelationCountBatcher}) render
 * hooks.
 *
 * The document-semantic validation (the always-on illuminate/validation bridge) and the
 * policy authorization gate hook into the create/update/delete arms; every unhandled
 * operation falls to the default arm, which
 * returns a `404` {@see ResourceNotFound} exactly like the bundle's `CrudOperationHandler`
 * default arm (never a `500`).
 */
final class CrudOperationHandler implements OperationHandlerInterface
{
    private ?ScopedRelationshipWrites $scopedWrites = null;

    private ?ScopedPolymorphicMembers $polymorphicMembers = null;

    /**
     * The identified `MorphToMany` writes awaiting {@see writeLinkRows()}, each with the members
     * its relation held before the write and their positions, by type and wire id.
     *
     * @var list<array{MorphToMany, ToManyRelationship, Mode, array<string, int>}>
     */
    private array $linkRowWrites = [];

    /**
     * The primary resources this operation loaded relations for, by object id.
     *
     * @var array<int, true>
     */
    private array $preloaded = [];

    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly DataPersisterRegistry $persisters,
        private readonly ResourceValidator $validator,
        private readonly FilterValueValidator $filterValidator,
        private readonly Authorizer $authorizer,
        private readonly TypeMetadataResolver $types,
        private readonly RelationCriteriaFactory $relationCriteria,
        private readonly RelatedIncludeBatcher $includeBatcher,
        private readonly RelationCountBatcher $countBatcher,
        private readonly RelationshipWindowBatcher $windowBatcher,
        private readonly RequestScopedRelationshipCount $relationshipCount,
        private readonly RequestScopedRelationshipPagination $relationshipPagination,
        private readonly RequestScopedRelationshipLinkage $relationshipLinkage,
        // Surface collaborators, optional so a stripped-down programmatic wiring
        // still constructs the handler: the custom-action invoker (its `CustomActionOperation`
        // arm), and the Atomic Operations executor's collaborators (the request-scoped
        // deferred-hook context, Laravel's router for `href` resolution, and an optional
        // logger for a post-commit hook that throws). Null on a pre-surface wiring → the
        // action arm 404s and the atomic arm is refused as unavailable.
        private readonly ?ActionInvoker $actions = null,
        private readonly ?WriteTransactionContext $txContext = null,
        private readonly ?Router $router = null,
        private readonly ?LoggerInterface $logger = null,
        // Discovery, so an Atomic Operations batch can re-apply the per-type operation
        // allow-list the router gates the direct HTTP surface with (a sub-operation never
        // touches the router). Null on a pre-surface wiring → the atomic backend gets an
        // empty allow-list and refuses every write, exactly as an unrouted type would.
        private readonly ?Discovery $discovery = null,
        // The lifecycle-event dispatcher: Laravel's event
        // Dispatcher, injected so every before/after lifecycle event fires through
        // `Event::listen`/`Event::fake`/the resource hook subscriber. Null on a
        // stripped-down programmatic wiring → every dispatch is a no-op (events are
        // an opt-in seam, exactly like the bundle's optional dispatcher).
        private readonly ?Dispatcher $dispatcher = null,
        // The related type's own read gates. Null on a stripped-down wiring leaves
        // a related to-many read gated by the parent alone.
        private readonly ?ViewableRows $viewableRows = null,
        // Names the server a dispatch that carries no request server runs on, so its
        // authorization reads that server's declarations.
        private readonly ?ServerNames $serverNames = null,
        // Decodes wire ids through the encoder of the dispatching server's resource class.
        private readonly ?IdEncoderResolver $idEncoders = null,
    ) {}

    public function handle(JsonApiOperationInterface $operation): DataResponse|RelatedResponse|IdentifierResponse|MetaResponse|NoContentResponse|AcceptedResponse|SeeOtherResponse|AtomicResultsResponse|ErrorResponse|RawResponse
    {
        // Clear the request-scoped `?withCount` seam at the very start of EVERY dispatch, so a
        // prior request's batched counts can never bleed into an arm that does not itself
        // install them (fetchRelationship, the to-one fetchRelated branch, every write). Each
        // read arm re-installs it below with the page it just fetched. The handler is a
        // singleton, so this per-dispatch reset holds regardless of the count holder's
        // container lifetime (the Symfony bundle's twin resets via kernel.reset; this is the
        // Laravel-side equivalent, not the — for a memoized Server, ineffective — scoped()
        // binding its own docblock once floated). The Relationship Queries profile's pagination
        // + linkage holders are cleared here too, so a prior profile read's windowed
        // pages/linkage can never leak into a render that does not re-install them (each
        // windowing arm re-sets them below).
        $this->relationshipCount->set(null);
        $this->relationshipPagination->set(null);
        $server = $operation->context()->server;
        $serverName = $this->serverNameOf($operation->context());
        $this->relationshipLinkage->bind($server instanceof Server ? $server : null, $serverName);
        $this->idEncoders?->bind($serverName);

        $this->preloaded = [];

        if ($operation instanceof CreateResourceOperation || $operation instanceof UpdateResourceOperation) {
            $this->assertObjectMembers($operation->body());
        }

        $response = match (true) {
            $operation instanceof FetchResourceOperation => $this->fetch($operation),
            $operation instanceof FetchRelatedOperation => $this->fetchRelated($operation),
            $operation instanceof FetchRelationshipOperation => $this->fetchRelationship($operation),
            $operation instanceof CreateResourceOperation => $this->create($operation),
            $operation instanceof UpdateResourceOperation => $this->update($operation),
            $operation instanceof DeleteResourceOperation => $this->delete($operation),
            $operation instanceof UpdateRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Replace),
            $operation instanceof AddToRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Add),
            $operation instanceof RemoveFromRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Remove),
            // The custom-action arm delegates to the (optional) invoker; a pre-surface
            // wiring with no invoker 404s exactly like the default arm.
            $operation instanceof CustomActionOperation => $this->actions?->invoke($operation) ?? ErrorResponse::fromException(new ResourceNotFound()),
            $operation instanceof AtomicOperationsOperation => $this->atomic($operation),
            default => ErrorResponse::fromException(new ResourceNotFound()),
        };

        $request = $operation->context()->httpRequest();

        return $response instanceof DataResponse && $server instanceof Server && $request instanceof JsonApiRequestInterface
            ? $this->loadRendered($server, $response, $request)
            : $response;
    }

    /**
     * Loads the relations of the resources a response renders that this operation did
     * not load them for: a custom action's resources, or ones an after-hook answered
     * with. They render through the same scoped preload, windows and counts a read
     * runs, so no linkage, included resource or count is read off the model.
     */
    private function loadRendered(Server $server, DataResponse $response, JsonApiRequestInterface $request): DataResponse
    {
        $groups = [];
        $loaded = true;
        foreach ($response->primaryResources() as $member) {
            if (!\is_object($member)) {
                continue;
            }
            $groups[$response->serializer()->getType($member)][] = $member;
            $loaded = $loaded && isset($this->preloaded[\spl_object_id($member)]);
        }
        if ($loaded || $groups === []) {
            return $response;
        }

        if (\count($groups) > 1) {
            $this->windowBatcher->refuse($request);
            $this->relationshipPagination->set(null);
            $this->relationshipLinkage->set(null);
            $this->preloadGroups($server, $groups, $request);
            $this->applyGroupedRelationshipCounts($server, $groups, $request);

            return $response;
        }

        $type = \array_key_first($groups);
        $members = $groups[$type];
        $this->preloadIncludes($server, $members, $type, $request);
        $activatedProfiles = $this->applyRelationshipWindows($server, $type, $members, $request);
        $this->applyRelationshipCounts($server, $type, $members, $request);

        return $activatedProfiles === [] ? $response : $response->withActivatedProfiles(...$activatedProfiles);
    }

    /**
     * `POST /operations` — the Atomic Operations batch. The single global handler grows
     * this one arm so an {@see AtomicOperationsOperation} dispatches through the SAME handler
     * as every CRUD operation: it builds a per-batch {@see AtomicLoopBackend} (which resolves
     * each sub-operation's target, applies it through THIS handler's own per-op CRUD arms
     * in-process, threads the shared local-id registry, and renders each result fragment) and
     * runs it through core's framework-agnostic {@see AtomicLoop} — which owns the ordering,
     * the all-or-nothing commit/rollback, and the failing-operation pointer prefixing.
     *
     * **Decoration wraps the batch, not each sub-op.** A handler decorator sees this one
     * {@see AtomicOperationsOperation}; the sub-operations re-enter `$this` directly.
     *
     * The {@see WriteTransactionContext} is what makes the sub-operations' After* lifecycle
     * hooks defer to post-commit; the router resolves an `href`-targeted operation. Absent
     * either, the batch cannot run transactionally, so it is refused as unsupported (a 500 —
     * a wiring fault, unreachable in a normally-built app).
     */
    private function atomic(AtomicOperationsOperation $operation): AtomicResultsResponse|ErrorResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);
        $request = $this->jsonApiRequest($operation->context());

        if ($this->txContext === null || $this->router === null) {
            return ErrorResponse::fromException(new AtomicOperationsUnavailable());
        }

        $backend = new AtomicLoopBackend(
            $operation->descriptors(),
            $server,
            $request,
            $this,
            $this->persisters,
            $this->txContext,
            $this->router,
            $this->exposedOperations($this->serverName($request)),
            $this->logger,
            $this->types,
        );

        return (new AtomicLoop())->run($operation->descriptors(), $backend);
    }

    /**
     * The per-type exposed CRUD operation allow-list ({@see Operation} case values keyed by
     * JSON:API type) the atomic backend gates sub-operations against — the same allow-list
     * the router emits the batch server's direct routes from, so a type on another server is
     * absent. Empty when no discovery is wired (a stripped-down programmatic handler), so the
     * backend refuses every write, exactly as an unrouted type.
     *
     * @return array<string, list<string>>
     */
    private function exposedOperations(string $server): array
    {
        if ($this->discovery === null) {
            return [];
        }

        $exposed = [];
        foreach ($this->discovery->resourcesFor($server) as $descriptor) {
            if ($descriptor->type !== '') {
                $exposed[$descriptor->type] = $descriptor->operations;
            }
        }

        return $exposed;
    }

    private function fetch(FetchResourceOperation $operation): DataResponse|SeeOtherResponse|ErrorResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $type = $operation->target()->type;
        $provider = $this->providers->forType($type);
        $serializer = $server->serializerFor($type);

        $request = $operation->context()->httpRequest();
        $request = $request instanceof JsonApiRequestInterface ? $request : null;

        $id = $operation->target()->id;
        if ($id !== null) {
            $model = $provider->fetchOne($type, $id);
            if ($model === null) {
                return ErrorResponse::fromException(new ResourceNotFound());
            }

            // The `view` policy gate authorizes the loaded model (404 already resolved),
            // before it is rendered — a denial is a `403` (thrown, rendered by the
            // exception renderer).
            $this->authorizer->authorize($type, Operation::FetchOne, $model, $this->serverNameOf($operation->context()));

            // Async-completion redirect (JSON:API 1.1 §"Asynchronous Processing"): when the
            // type's serializer resolves a completion location for this resource, a GET on the
            // job resource redirects there with a `303 See Other` instead of rendering — so a
            // client polling the job follows `Location` to the produced resource once the work
            // is done. The OpenAPI twin is a `new SeeOther()` fetch-one declaration; this hook drives the
            // runtime. Resolved before includes/counts, since a redirect renders no body.
            if ($serializer instanceof ResolvesCompletionRedirect) {
                $location = $serializer->completionLocation($model);
                if ($location !== null) {
                    return SeeOtherResponse::to($location);
                }
            }

            // Batch eager-load the effective ?include tree (explicit or the resource's
            // default-include fallback) so includes do not N+1; a single resource is
            // preloaded as a one-element list. Window each rendered to-many relation under the
            // Relationship Queries profile (page 1 of its relatedQuery-ordered/filtered set,
            // supplied out-of-band) BEFORE the count hook (windows → counts), then install the
            // ?withCount batched counts for this resource so its relationship objects render
            // meta.total.
            $this->preloadIncludes($server, [$model], $type, $request);
            $activatedProfiles = $this->applyRelationshipWindows($server, $type, [$model], $request);
            $this->applyRelationshipCounts($server, $type, [$model], $request);

            $response = DataResponse::fromResource($model, $serializer);

            // The after-fetch-one lifecycle hook (post-fetch) may replace the response
            // — a custom-action shaping of the read. Skipped for a programmatic
            // dispatch with no request to build the event from.
            if ($request !== null) {
                $event = new AfterFetchOneEvent($type, $request, $model, $this->serverName($request));
                $this->dispatch($event);
                $response = $event->response() ?? $response;
            }

            // Surface any profile a cursor-paginated included relation activated
            // (e.g. `?include=<cursorRelation>`), even when the primary resource
            // document itself carries no page profile.
            return $activatedProfiles === [] ? $response : $response->withActivatedProfiles(...$activatedProfiles);
        }

        // An all-or-nothing collection gate runs BEFORE the query: a listener may deny
        // the whole `GET /{type}` read (a throw → 403/…) so a blocked caller never
        // triggers a collection query. Skipped for a programmatic dispatch with no
        // request. Row-level read authorization still belongs in the provider query
        // scope; this gate blanket-blocks the whole collection.
        if ($request !== null) {
            $this->dispatch(new BeforeFetchCollectionEvent($type, $request, $this->serverName($request)));
        }

        // A bare serializer/hydrator pair declares no field inventory, so it has no
        // filter/sort vocabulary and no resource-level paginator.
        $resource = $server->hasResourceFor($type) ? $server->resourceFor($type) : null;

        $filters = $resource?->allFilters() ?? [];
        $paths = FilterPaths::of($server, $resource);

        // The always-on filter-value gate: a client-supplied
        // `filter[<key>]` value is validated against the filter's declared value
        // constraints BEFORE the provider runs, so a mistyped value (e.g.
        // `filter[trackRange][min]=banana`, or a calendar-invalid DateRange bound) is a
        // clean `400` FILTER_VALUE_INVALID rather than a silent non-match or a driver
        // error. The raw requested map is validated (never the author-set defaults).
        $this->filterValidator->validate($operation->queryParameters()->filter, $filters, $request, $paths);

        // The `viewAny` policy gate authorizes the collection BEFORE the query runs, on
        // a blank instance minted for the type's class (so a policy's `viewAny($user)` is
        // called). A read-only type has no persister to mint one — but a DECLARED `policy:`
        // is still enforced against the resource-class token (never fail-open); only the
        // no-policy Gate path stays inert on a null subject (the model's policy cannot be
        // resolved without an instance).
        $listSubject = $this->persisters->supportsType($type) ? $this->persisters->forType($type)->instantiate($type) : null;
        $this->authorizer->authorize($type, Operation::FetchCollection, $listSubject, $this->serverNameOf($operation->context()));

        // A singular filter the client applied collapses the collection to a
        // zero-to-one response — a single resource (the first match) or null, never
        // an array, and never paginated (core's docs/filters.md).
        $singular = $this->appliesSingularFilter($filters, $operation->queryParameters(), $request);

        // The resource's pagination() return is the single source of truth:
        // used verbatim, with `null` meaning *no pagination* (fetch-all). The base
        // impl returns the resolved server default. A singular filter collapses to a
        // zero-to-one response, so it is never paginated.
        $paginator = $singular ? null : ($resource !== null ? $resource->pagination($server->defaultPaginator()) : $server->defaultPaginator());
        // Resolve a MultiPaginator to the concrete strategy this request selects, once
        // and up front, so every downstream `instanceof CursorPaginator` render/count
        // branch sees the child rather than the wrapper.
        if ($paginator !== null && $request !== null) {
            $paginator = $paginator->resolve($request);
        }
        $window = $paginator !== null && $request !== null ? $paginator->window($request) : null;

        // The single COUNT decision: a paginator counts when its own withCount()
        // author opt-in flipped it, OR the client asked `?withCount=_self_` (already
        // 400-ed by core's document gate if the resource is not countable(), so an
        // accepted `_self_` here implies countable). A cursor paginator has no author
        // opt-in, so only `_self_` counts a cursor page.
        $wantsCount = $paginator !== null
            && ($paginator->wantsCount() || ($request !== null && $request->countsRelationship('_self_')));

        $result = $provider->fetchCollection($type, new CollectionCriteria(
            $operation->queryParameters(),
            $filters,
            $resource?->allSorts() ?? [],
            $window,
            // Applied only when the request carries no `sort` (core's docs/sorts.md).
            $resource?->defaultSort() ?? [],
            wantsCount: $wantsCount,
            request: $request,
            paths: $paths,
        ));

        $items = $this->materialize($result);

        // Batch eager-load the effective ?include tree across the whole page (so includes
        // do not N+1), window each rendered to-many relation under the Relationship Queries
        // profile in ONE batched push-down per relation (windows → counts), and install the
        // ?withCount batched counts in ONE grouped count per relation, before rendering —
        // covering the singular first match too.
        $this->preloadIncludes($server, $items, $type, $request);
        // The returned profiles are the ones a cursor-paginated included relation
        // activated, surfaced onto each collection response below.
        $activatedProfiles = $this->applyRelationshipWindows($server, $type, $items, $request);
        $this->applyRelationshipCounts($server, $type, $items, $request);

        if ($singular) {
            return $this->afterFetchCollection(DataResponse::fromResource($items[0] ?? null, $serializer), $type, $request, $items, null, $activatedProfiles);
        }

        // A cursor (keyset) page: the provider minted the boundary tokens, so render
        // through the paginator's cursor path (CursorPaginator::fromBoundaries)
        // carrying the pre-minted prev/next tokens + the has-flags. `from`/`to` are
        // the wire ids of the first/last rendered rows (meta.page.from/to). A
        // `?withCount=_self_` total renders as the top-level meta.total only: a cursor
        // page has no meta.page.total or last.
        if ($result instanceof CursorCollectionResult && $paginator instanceof CursorPaginator && $request !== null) {
            $from = $items === [] ? null : $serializer->getId($items[0]);
            $to = $items === [] ? null : $serializer->getId($items[\array_key_last($items)]);

            return $this->afterFetchCollection(
                $this->withCursorTotal(DataResponse::fromPage(
                    $paginator->fromBoundaries(
                        $request,
                        $items,
                        $result->cursorBefore ?? '',
                        $result->cursorAfter ?? '',
                        $result->hasMore,
                        $result->hasPrevious,
                        from: $from,
                        to: $to,
                    ),
                    $serializer,
                ), $result),
                $type,
                $request,
                $items,
                $paginator,
                $activatedProfiles,
            );
        }

        // Fan the single resolved total to BOTH meta slots from one count:
        // fetch-all renders meta.total unconditionally; a counted page carries
        // meta.page.total/links.last and echoes meta.total; a count-free page renders
        // self/first/prev/next (no total/last, next via hasMore) and NO meta.total.
        if ($paginator === null) {
            return $this->afterFetchCollection(
                DataResponse::fromCollection($items, $serializer)->withMeta(['total' => \count($items)]),
                $type,
                $request,
                $items,
                $paginator,
                $activatedProfiles,
            );
        }

        if ($request !== null && $result->total !== null) {
            return $this->afterFetchCollection(
                DataResponse::fromPage($paginator->paginate($request, $items, $result->total), $serializer)
                    ->withMeta(['total' => $result->total]),
                $type,
                $request,
                $items,
                $paginator,
                $activatedProfiles,
            );
        }

        if ($request !== null && $result->windowed) {
            return $this->afterFetchCollection(
                DataResponse::fromPage($paginator->paginateWithoutCount($request, $items, $result->hasMore), $serializer),
                $type,
                $request,
                $items,
                $paginator,
                $activatedProfiles,
            );
        }

        return $this->afterFetchCollection(DataResponse::fromCollection($items, $serializer), $type, $request, $items, $paginator, $activatedProfiles);
    }

    /**
     * `GET /{type}/{id}/{relationship}` — the related-resource(s) document. Loads the
     * parent, refuses a relation hidden from this caller, resolves the named relation (a
     * `404` if absent or its related endpoint is suppressed), gates the read (the parent's `view` policy, or the relation's own
     * `securityRead` override), then renders through the related type's serializer per
     * cardinality:
     *  - a **to-many** resolves the related resource's filter/sort/pagination vocabulary
     *    merged with the relation's own scoped filters/sorts into a {@see CollectionCriteria},
     *    asks the related provider's {@see \haddowg\JsonApiLaravel\DataProvider\DataProviderInterface::fetchRelatedCollection()}
     *    to execute it (scoped to the parent), preloads the related page's own `?include`s,
     *    and renders a paginated {@see RelatedResponse::fromPage()} (a cursor (keyset) page
     *    through the paginator's `fromBoundaries()` carrying the provider-minted tokens,
     *    otherwise counted / count-free per the relation's `countable()`) or a fetch-all
     *    {@see RelatedResponse::fromCollection()};
     *  - a **polymorphic to-many** has no single related type — no shared filter/sort
     *    vocabulary, no related-resource paginator — and renders its mixed members through a
     *    {@see PolymorphicSerializer}; its includes are not batch-preloaded (it renders lazily);
     *  - a **to-one** reads the related object off the parent, resolves the serializer FROM
     *    that object (so a polymorphic to-one renders the object's own type), and renders a
     *    single resource (`data:null` for an empty to-one); a `?filter` may null a monomorphic
     *    to-one via {@see \haddowg\JsonApiLaravel\DataProvider\DataProviderInterface::relatedToOneMatches()}.
     */
    private function fetchRelated(FetchRelatedOperation $operation): RelatedResponse|ErrorResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $target = $operation->target();
        $type = $target->type;
        $relationshipName = (string) $target->relationship;

        $parent = $this->loadParent($type, $target->id);
        if ($parent === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        // A relation hidden from this caller (a `hidden($when)` predicate needs the parent)
        // is a 404, the same as in the rendered resource.
        $server->refuseHiddenRelationRead($target, $parent, $this->jsonApiRequest($operation->context()));

        $relation = $this->resolveRelation($server, $type, $relationshipName);
        if ($relation === null || !$relation->exposesRelatedEndpoint()) {
            return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
        }

        $this->gateRead($type, $parent, $relation, $this->serverNameOf($operation->context()));
        $this->gateRelatedList($type, $parent, $relation);

        $relatedTypes = $relation->relatedTypes();
        $polymorphic = Polymorphism::of($relation);
        $relatedType = $relatedTypes[0] ?? $type;

        $request = $this->jsonApiRequest($operation->context());

        // Bundle parity: the parent reached this read through the SAME single-resource fetch
        // the primary read fires AfterFetchOne on, so fire it here too — a resource's
        // afterFetchOne hook runs on related reads exactly as it does in the bundle. The
        // related path renders the related value(s), not the parent, so any response the hook
        // returns is (harmlessly) ignored. Security is already enforced by gateRead() above.
        $this->dispatch(new AfterFetchOneEvent($type, $request, $parent, $this->serverName($request)));

        // The relation-scoped before-fetch-related lifecycle event (a Laravel addition, always
        // fired as a pure lifecycle seam — read authorization is the policy gate above): a
        // listener that throws a JsonApiException aborts the read.
        $this->dispatch(new BeforeFetchRelatedEvent($type, $request, $parent, $relation, $this->serverName($request)));

        if ($relation->isToMany()) {
            $relatedResource = $polymorphic ? null : $this->types->resourceFor($server, $relatedType);
            $paginator = $this->relationCriteria->paginatorFor($relation, $relatedResource, $server);
            // Resolve a MultiPaginator to the concrete child before any cursor branch.
            $paginator = $paginator?->resolve($request);
            $window = $paginator?->window($request);

            // The related endpoint renders via CollectionDocument, which does not run core's
            // primary-collection `_self_` gate — so `?withCount=_self_` on a non-countable
            // relation is enforced here as a 400.
            if ($request->countsRelationship('_self_') && !$relation->isCountable()) {
                return ErrorResponse::fromException(new RelationshipCountNotAllowed(['_self_']));
            }

            $relWantsCount = $paginator !== null
                && ($paginator->wantsCount() || $request->countsRelationship('_self_'));

            $relatedProvider = $this->providers->forType($relatedType);

            // A provider that queries the pivot join also accepts the relation's pivot
            // filters and sorts, and returns each member with its pivot row.
            $pivotQuery = !$polymorphic && $this->pivotQueryingProvider($parent, $relation) !== null;

            $criteria = $this->relationCriteria->criteriaFor(
                $operation->queryParameters(),
                $relatedResource,
                $relation,
                $window,
                wantsCount: $relWantsCount,
                includePivotFields: $pivotQuery,
                request: $request,
                server: $server,
                parentType: $type,
            );

            // Validate a client-supplied filter value against the merged vocabulary (related
            // resource ⊕ relation-scoped), so a mistyped value is the same 400 as elsewhere.
            $this->filterValidator->validate($operation->queryParameters()->filter, $criteria->filters, $request, $criteria->paths);

            // A relation over several types reaching a scoped type is read off the parent and
            // narrowed per member type before the page is cut, so the page stays full.
            // Over one type, that type's own provider reads it in one query.
            // A MorphToMany declared with identifiedBy() pages its link rows, sorted and filtered by
            // their pivot fields and narrowed by each scoped type, and loads only the page's rows.
            // Each row renders as its pair's type, which a class serving several types can't
            // report, with the `meta.pivot` of its link row.
            $identifiedTypes = null;
            $identifiedPivots = [];
            if (IdentifiedMorphMembers::identifies($relation)) {
                $this->filterValidator->validate($operation->queryParameters()->filter, LinkRowQuery::filters($relation), $request);
                $identifiedTypes = [];
                $result = $this->relationshipLinkage->members()->page($server, $parent, $relation, $criteria, $request, $identifiedTypes, $this->linkRows($type), $identifiedPivots);
            } else {
                $result = \count($relatedTypes) > 1 && $this->providers->relatesToScopedType($type, $relation)
                    ? $this->polymorphicMembers()->window($criteria, $this->narrowedMembers($server, $type, $parent, $relation, $request))
                    : $relatedProvider->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
            }

            $serializer = match (true) {
                $identifiedTypes !== null => $this->identifiedSerializer($relation, $server, $identifiedTypes, $identifiedPivots),
                $polymorphic => $this->polymorphicSerializer($relation, $server),
                default => $server->serializerFor($relatedType),
            };

            $items = $this->materialize($result);

            // A belongsToMany with declared pivot fields renders each related member's stored
            // pivot values as `meta.pivot`: wrap the related serializer with the
            // per-member pivot map (Eloquent reads it off the fetched page; the in-memory
            // witness returns none, so the wrap is a no-op there).
            if (!$polymorphic) {
                $pivotMap = $this->pivotMap($server, $type, $parent, $relation, $items);
                if ($this->rendersPivotMeta($relation, $pivotMap)) {
                    \assert($relation instanceof BelongsToMany);
                    $serializer = new PivotMetaSerializer($serializer, $pivotMap, $relation, $parent, $server->outputTimezone());
                }
            }

            // A polymorphic page spans types (no single related type), so its relations are not
            // windowed. Its includes, linkage and counts are read per member type, so a member's
            // own relation into a scoped type shows only the rows that type admits.
            if (!$polymorphic) {
                $this->preloadIncludes($server, $items, $relatedType, $request);
                $this->applyRelationshipWindows($server, $relatedType, $items, $request);
                $this->applyRelationshipCounts($server, $relatedType, $items, $request);
            } else {
                $this->windowBatcher->refuse($request);
                $groups = $this->groupByRenderedType($server, $relation, $items, $identifiedTypes ?? []);
                $this->preloadGroups($server, $groups, $request);
                $this->applyGroupedRelationshipCounts($server, $groups, $request);
            }

            // A cursor (keyset) related page: the provider minted the boundary tokens
            // over the parent-scoped set, so render through the paginator's cursor path
            // (CursorPaginator::fromBoundaries) exactly as the primary collection does —
            // core scopes the prev/next links to the related URL, emits no `last`, and
            // carries meta.page{perPage,from,to,hasMore}. A `?withCount=_self_` total
            // renders as the top-level meta.total only.
            if ($result instanceof CursorCollectionResult && $paginator instanceof CursorPaginator) {
                $from = $items === [] ? null : $serializer->getId($items[0]);
                $to = $items === [] ? null : $serializer->getId($items[\array_key_last($items)]);

                return $this->withCursorTotal(RelatedResponse::fromPage(
                    $paginator->fromBoundaries(
                        $request,
                        $items,
                        $result->cursorBefore ?? '',
                        $result->cursorAfter ?? '',
                        $result->hasMore,
                        $result->hasPrevious,
                        from: $from,
                        to: $to,
                    ),
                    $serializer,
                    $relation->isCountable(),
                ), $result);
            }

            // Counted page: the single total fans to BOTH meta.page.total (inside the
            // count-based page) AND the universal top-level meta.total.
            if ($paginator !== null && $result->total !== null) {
                return RelatedResponse::fromPage($paginator->paginate($request, $items, $result->total), $serializer, $relation->isCountable())
                    ->withMeta(['total' => $result->total]);
            }

            // A count-free page: a non-countable relation's windowed fetch carries no total,
            // only `hasMore` — render self/first/prev/next, no total/last.
            if ($paginator !== null && $result->windowed) {
                return RelatedResponse::fromPage($paginator->paginateWithoutCount($request, $items, $result->hasMore), $serializer, $relation->isCountable());
            }

            // Fetch-all (no paginator): the whole related set is materialized, so its size is
            // free — render meta.total unconditionally.
            return RelatedResponse::fromCollection($items, $serializer, $relation->isCountable())
                ->withMeta(['total' => \count($items)]);
        }

        // A to-one related endpoint has no collection, so `?withCount=_self_` is invalid here.
        if ($request->countsRelationship('_self_')) {
            throw new RelationshipCountNotAllowed(['_self_']);
        }

        // A target whose type has its own scoping provider shows only if that provider serves it,
        // and comes from that provider's batch when it answers for this parent.
        $this->relationshipLinkage->loadToOne([$parent], $type, $relation, $request);
        $related = $this->relationshipLinkage->toOneTargetFor($parent, $relation, $request);

        // A polymorphic to-one (MorphTo) has no single related resource and so no shared
        // filter vocabulary — any requested filter key is unrecognised (a 400), gated on the
        // requested filter being present so a filter on an empty polymorphic to-one still 400s.
        // It renders several types, so no `relatedQuery` path names a relation of it.
        if ($polymorphic) {
            $filter = $operation->queryParameters()->filter;
            if ($filter !== []) {
                throw new FilterParamUnrecognized((string) \array_key_first($filter));
            }
            $this->windowBatcher->refuse($request);
        }

        // A relation filter that excludes the single related object nulls the to-one, so it
        // renders `data: null` — the to-one twin of the to-many endpoint's filtered
        // collection. Monomorphic only. The local `$related` is nulled (no parent mutation).
        // The filter is the endpoint's own: a `relatedQuery` path names a relation of the
        // related resource, never this one.
        $windowed = !$polymorphic;
        if (\is_object($related) && !$polymorphic) {
            $filter = $operation->queryParameters()->filter;
            if ($filter !== []) {
                $relatedResource = $this->types->resourceFor($server, $relatedType);
                $criteria = $this->relationCriteria->criteriaFor(
                    new QueryParameters(fields: [], includes: [], sort: [], filter: $filter, pagination: $request->getPagination()),
                    $relatedResource,
                    $relation,
                    null,
                    request: $request,
                    server: $server,
                    parentType: $type,
                );
                $this->filterValidator->validate($filter, $criteria->filters, $request, $criteria->paths);

                if (!$this->providers->forType($relatedType)->relatedToOneMatches($relatedType, $related, $relation, $criteria, $request)) {
                    $related = null;
                }
            }
        }

        // An identified MorphTo's target loaded as the type its parent stores, so it renders,
        // and preloads its own includes, as that type.
        $pair = \is_object($related) && IdentifiedMorphTargets::identifies($relation) ? $relation->storedIdentifier($parent, $request) : null;
        if ($pair !== null) {
            $relatedType = $pair[0];
            $polymorphic = false;
        }

        $serializer = $pair !== null
            ? $server->serializerFor($relatedType)
            : $relation->resolveSerializer($related, $server) ?? $server->serializerFor($relatedType);

        // Preload the related resource's own ?include tree (a single related object as a
        // one-element list) and install its `?withCount` counts, so a `?withCount=<rel>` named
        // against the rendered to-one target emits `meta.total` on its relationship object —
        // parity with the single-resource fetch, and (with the per-dispatch clear above) never
        // a stale count. The related type may have no provider of its own (only ever resolved
        // through the parent), so guard the resolution. A MorphTo target loads as its own type.
        $targetType = \is_object($related) && $polymorphic ? $serializer->getType($related) : $relatedType;
        if (\is_object($related) && $this->providers->supportsType($targetType)) {
            $this->preloadIncludes($server, [$related], $targetType, $request);
            // Its relationships honour the Relationship Queries profile like any rendered resource,
            // so their linkage, included members and counts come from the same filtered set.
            if ($windowed) {
                $activatedProfiles = $this->applyRelationshipWindows($server, $targetType, [$related], $request);
            }
            $this->applyRelationshipCounts($server, $targetType, [$related], $request);
        } elseif ($windowed) {
            // An absent target still checks the `relatedQuery` paths.
            $this->applyRelationshipWindows($server, $relatedType, [], $request);
        }

        $response = RelatedResponse::fromResource($related, $serializer);

        return ($activatedProfiles ?? []) === [] ? $response : $response->withActivatedProfiles(...$activatedProfiles);
    }

    /**
     * `GET /{type}/{id}/relationships/{relationship}` — the relationship-linkage document
     * (resource identifiers only). Loads the parent, refuses a relation hidden from this
     * caller, resolves the named relation (a `404` if absent or its relationship endpoint is
     * suppressed), gates the read, and routes the
     * parent through the *parent* type's serializer with the relationship name set so the
     * transformer emits linkage.
     *
     * A monomorphic to-many relationship endpoint is a queryable, paginated collection at
     * parity with its related twin (`GET /{type}/{id}/{rel}`): when the client supplies
     * `?page`/`?sort`/`?filter` (or `?withCount=_self_`) it is windowed to page 1, whose
     * linkage + relationship-object pagination ride the request-scoped seams OUT-OF-BAND (never
     * a destructive write onto the parent property, which would corrupt a column-sharing sibling
     * relation). A cursor-paginated relation windows to a real keyset page (the minted
     * `page[after]`/`page[before]` tokens on the links), attached to the response via core's
     * {@see IdentifierResponse::withPage()} so its profile is advertised while the body stays
     * links-only (core's docs/responses.md). It pages by default, so a plain read with no
     * query parameters serves page 1 of the relation's paginator. A pivot
     * `belongsToMany` windows the same way, and each member of the page carries its
     * `meta.pivot` from the fetched pivot rows. A polymorphic to-many has no
     * windowed linkage here and renders the whole association.
     */
    private function fetchRelationship(FetchRelationshipOperation $operation): IdentifierResponse|ErrorResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $target = $operation->target();
        $type = $target->type;
        $relationshipName = (string) $target->relationship;

        $parent = $this->loadParent($type, $target->id);
        if ($parent === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $server->refuseHiddenRelationRead($target, $parent, $this->jsonApiRequest($operation->context()));

        $relation = $this->resolveRelation($server, $type, $relationshipName);
        if ($relation === null || !$relation->exposesRelationshipEndpoint()) {
            return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
        }

        $this->gateRead($type, $parent, $relation, $this->serverNameOf($operation->context()));
        $this->gateRelatedList($type, $parent, $relation);

        // When the client addressed the relationship (linkage) endpoint with query parameters
        // (`?page`/`?sort`/`?filter`/`?withCount=_self_`), honour or reject them — NEVER silently
        // ignore them (the worst outcome: a client believing its sort/filter/page applied).
        $request = $this->jsonApiRequest($operation->context());

        // Bundle parity: the parent reached this read through the SAME single-resource fetch
        // the primary read fires AfterFetchOne on, so fire it here too (a resource's
        // afterFetchOne hook runs on relationship reads). The linkage path renders the
        // relationship, not the parent, so any response the hook returns is ignored. Security
        // is already enforced by gateRead() above.
        $this->dispatch(new AfterFetchOneEvent($type, $request, $parent, $this->serverName($request)));

        // The relation-scoped before-fetch-relationship lifecycle event (a Laravel addition,
        // always fired as a pure lifecycle seam — read authorization is the policy gate
        // above): a listener that throws a JsonApiException aborts the read.
        $this->dispatch(new BeforeFetchRelationshipEvent($type, $request, $parent, $relation, $this->serverName($request)));

        $page = null;
        $pivotMap = null;
        $total = null;
        $queried = $this->relationshipEndpointQueried($operation->queryParameters(), $request);
        $relatedTypes = $relation->relatedTypes();

        if (IdentifiedMorphMembers::identifies($relation)) {
            // An identified MorphToMany pages its admitted link rows exactly as its related
            // endpoint does, sorted and filtered by their pivot fields, and renders them without
            // loading a member row.
            $this->filterValidator->validate($operation->queryParameters()->filter, LinkRowQuery::filters($relation), $request);
            $windowed = $this->windowIdentifiedRelationshipEndpoint($server, $type, $parent, $relation, $operation->queryParameters(), $request);
            if ($windowed instanceof ErrorResponse) {
                return $windowed;
            }
            ['page' => $page, 'total' => $total] = $windowed;
        } elseif ($relation->isToMany() && Polymorphism::isMonomorphic($relation)
            && ($queried || $this->providers->supportsType($relatedTypes[0]))) {
            // A monomorphic to-many (pivot or not) is paginated by default, queried or not, so
            // the plain read serves the page the OpenAPI document advertises. A miss (unknown
            // filter/sort key, a `_self_` count on a non-countable relation) surfaces the
            // related endpoint's same error.
            $windowed = $this->windowRelationshipEndpoint($server, $type, $parent, $relation, $operation->queryParameters(), $request);
            if ($windowed instanceof ErrorResponse) {
                return $windowed;
            }
            ['page' => $page, 'pivotMap' => $pivotMap, 'total' => $total] = $windowed;
        } elseif ($queried) {
            // The endpoint cannot honour the client's query on THIS relation shape, so it is a
            // 400 rather than a silent full-set render: a to-one has no collection to
            // sort/page/count, and a polymorphic to-many has no single related provider or
            // shared sort/filter vocabulary. See ADR 0006.
            return $this->rejectRelationshipEndpointQuery($operation->queryParameters(), $request);
        } elseif ($relation->isToMany() && $this->providers->relatesToScopedType($type, $relation)) {
            // The whole association, but loaded through the related type's own scoping provider
            // rather than read off the parent, so it lists exactly what the related endpoint does.
            $this->scopeRelationshipEndpoint($server, $type, $parent, $relation, $request);
        } elseif (!$relation->isToMany()) {
            // A to-one the related type's own provider answers in its batch takes its target from it.
            $this->relationshipLinkage->loadToOne([$parent], $type, $relation, $request);
        }

        // The linkage serializer may wrap the parent's (a pivot relation's does), which hides
        // the relation from core's `_self_` gate, so the relation's countable() is handed over.
        $response = IdentifierResponse::forRelationship(
            $parent,
            $this->relationshipLinkageSerializer($server, $type, $parent, $relation, $relationshipName, $pivotMap),
            $relationshipName,
            $relation->isCountable(),
        );

        // A linkage page counted for `?withCount=_self_` renders its total as the top-level
        // meta.total, as a counted collection does.
        if ($total !== null) {
            $response = $response->withMeta(['total' => $total]);
        }

        // Attach the page that windowed the linkage (core's docs/responses.md): the body stays
        // links-only (the pagination links ride the request-scoped seam, no meta.page),
        // but a page that activates a profile — the cursor page — is then advertised in
        // `jsonapi.profile` + the Content-Type `profile` parameter, exactly as
        // RelatedResponse::fromPage does on the related endpoint.
        return $page !== null ? $response->withPage($page) : $response;
    }

    /**
     * A cursor page's `?withCount=_self_` total, as the top-level `meta.total`. A cursor page
     * has no `meta.page.total` or `last` link to carry it.
     *
     * @template T of DataResponse|RelatedResponse
     *
     * @param T                        $response
     * @param CollectionResult<object> $result
     *
     * @return T
     */
    private function withCursorTotal(DataResponse|RelatedResponse $response, CollectionResult $result): DataResponse|RelatedResponse
    {
        return $result->total === null ? $response : $response->withMeta(['total' => $result->total]);
    }

    /**
     * Whether the client addressed a relationship (linkage) endpoint with query parameters: an
     * explicit `?page`/`?sort`/`?filter`, or a `?withCount=_self_` naming this relationship's
     * collection total. A relation shape that cannot be windowed rejects them.
     */
    private function relationshipEndpointQueried(QueryParameters $queryParameters, JsonApiRequestInterface $request): bool
    {
        return $queryParameters->pagination !== []
            || $queryParameters->sort !== []
            || $queryParameters->filter !== []
            || $request->countsRelationship('_self_');
    }

    /**
     * Windows a monomorphic to-many relationship (linkage) endpoint to page 1 of the request's
     * `?sort`/`?filter` over the merged related-collection vocabulary (mirroring
     * {@see fetchRelated()}'s to-many arm), then supplies that page-1 linkage + its pagination
     * out-of-band via the request-scoped seams. Returns an {@see ErrorResponse} for a
     * `?withCount=_self_` on a non-countable relation (the linkage twin of the related
     * endpoint's `_self_` gate). On success it returns the built page (`null` when the relation
     * is not paginated), so the caller can attach it to the response via
     * {@see IdentifierResponse::withPage()}, for a pivot relation the page members' pivot
     * map the linkage renders as `meta.pivot`, and the total a `?withCount=_self_` asked for.
     *
     * @return ErrorResponse|array{page: PageInterface<mixed>|null, pivotMap: array<string, array<string, mixed>>, total: int|null}
     */
    private function windowRelationshipEndpoint(
        Server $server,
        string $type,
        object $parent,
        RelationInterface $relation,
        QueryParameters $queryParameters,
        JsonApiRequestInterface $request,
    ): ErrorResponse|array {
        $relatedType = $relation->relatedTypes()[0] ?? $type;
        $relatedResource = $this->types->resourceFor($server, $relatedType);
        $paginator = $this->relationCriteria->paginatorFor($relation, $relatedResource, $server);
        // Resolve a MultiPaginator to the concrete child before any cursor branch.
        $paginator = $paginator?->resolve($request);
        $window = $paginator?->window($request);

        // `_self_` names THIS relationship's collection — a 400 on a non-countable relation (the
        // to-many linkage twin of fetchRelated's gate; the relationship document does not run
        // core's primary-collection `_self_` gate).
        if ($request->countsRelationship('_self_') && !$relation->isCountable()) {
            return ErrorResponse::fromException(new RelationshipCountNotAllowed(['_self_']));
        }

        $wantsCount = $paginator !== null
            && ($paginator->wantsCount() || $request->countsRelationship('_self_'));

        $criteria = $this->relationCriteria->criteriaFor(
            $queryParameters,
            $relatedResource,
            $relation,
            $window,
            wantsCount: $wantsCount,
            includePivotFields: $this->pivotQueryingProvider($parent, $relation) !== null,
            request: $request,
            server: $server,
            parentType: $type,
        );
        $this->filterValidator->validate($queryParameters->filter, $criteria->filters, $request, $criteria->paths);

        $result = $this->providers->forType($relatedType)->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
        $items = $this->materialize($result);

        return [
            'page' => $this->supplyWindowedRelationship($parent, $relation->name(), $items, $paginator, $result, $wantsCount, $request, $queryParameters),
            'pivotMap' => $this->pivotMap($server, $type, $parent, $relation, $items),
            'total' => $request->countsRelationship('_self_') ? $result->total : null,
        ];
    }

    /**
     * Windows an identified MorphToMany's relationship endpoint: its admitted pairs paged by the
     * relation's paginator, supplied out-of-band as the linkage, with the page for the links and the total a `?withCount=_self_` asked for.
     *
     * @return ErrorResponse|array{page: PageInterface<mixed>|null, total: int|null}
     */
    private function windowIdentifiedRelationshipEndpoint(
        Server $server,
        string $type,
        object $parent,
        MorphToMany $relation,
        QueryParameters $queryParameters,
        JsonApiRequestInterface $request,
    ): ErrorResponse|array {
        $paginator = $this->relationCriteria->paginatorFor($relation, null, $server)?->resolve($request);

        if ($request->countsRelationship('_self_') && !$relation->isCountable()) {
            return ErrorResponse::fromException(new RelationshipCountNotAllowed(['_self_']));
        }

        $wantsCount = $paginator !== null
            && ($paginator->wantsCount() || $request->countsRelationship('_self_'));

        $criteria = $this->relationCriteria->criteriaFor($queryParameters, null, $relation, $paginator?->window($request), wantsCount: $wantsCount, request: $request);
        $result = $this->relationshipLinkage->members()->pageMembers($server, $parent, $relation, $criteria, $request, $this->linkRows($type));

        return [
            'page' => $this->supplyWindowedRelationship($parent, $relation->name(), $this->materialize($result), $paginator, $result, $wantsCount, $request, $queryParameters),
            'total' => $request->countsRelationship('_self_') ? $result->total : null,
        ];
    }

    /**
     * Supplies a plain (unqueried) to-many relationship endpoint's linkage out-of-band from the
     * related type's own scoping provider. A polymorphic relation is read off the parent and
     * narrowed per member type instead.
     *
     * The whole association is listed whatever the request's query says, so a mutation echoes
     * every member it can see even when the request carries `?sort` or `?filter`.
     */
    private function scopeRelationshipEndpoint(
        Server $server,
        string $type,
        object $parent,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): void {
        $relatedTypes = $relation->relatedTypes();
        if (\count($relatedTypes) !== 1) {
            $this->relationshipLinkage->scope(new WindowedRelationshipLinkage(
                [\spl_object_id($parent) => [$relation->name() => new RelationshipLinkage($this->narrowedMembers($server, $type, $parent, $relation, $request))]],
            ));

            return;
        }

        $provider = $this->providers->scopedRelatedProvider($type, $relatedTypes[0]);
        \assert($provider !== null);

        $items = $this->materialize(
            $provider->fetchRelatedCollection($relatedTypes[0], $parent, $relation, new CollectionCriteria(new QueryParameters([], [], [], [], []), request: $request), $request),
        );

        $this->relationshipLinkage->scope(new WindowedRelationshipLinkage(
            [\spl_object_id($parent) => [$relation->name() => new RelationshipLinkage($items)]],
        ));
    }

    /**
     * The members a polymorphic `$relation` holds on `$parent`, narrowed per member type to the
     * rows each scoped type's provider admits.
     *
     * @return list<object>
     */
    private function narrowedMembers(Server $server, string $type, object $parent, RelationInterface $relation, JsonApiRequestInterface $request): array
    {
        $value = $relation->readValue($parent, $request);

        return $this->polymorphicMembers()->narrow($server, $type, $relation, [\is_iterable($value) ? $value : []])[0];
    }

    private function polymorphicMembers(): ScopedPolymorphicMembers
    {
        return $this->polymorphicMembers ??= new ScopedPolymorphicMembers($this->providers);
    }

    /**
     * Rejects a query the relationship (linkage) endpoint cannot honour on the addressed
     * relation shape — a to-one or a polymorphic to-many — with
     * a `400` rather than silently rendering the full association. `?withCount=_self_` is the
     * related endpoint's same {@see RelationshipCountNotAllowed}; a filter mirrors
     * {@see fetchRelated()}'s polymorphic-to-one {@see FilterParamUnrecognized}; a `?sort`/`?page`
     * is an unsupported query parameter on this endpoint.
     */
    private function rejectRelationshipEndpointQuery(QueryParameters $queryParameters, JsonApiRequestInterface $request): ErrorResponse
    {
        if ($request->countsRelationship('_self_')) {
            return ErrorResponse::fromException(new RelationshipCountNotAllowed(['_self_']));
        }

        if ($queryParameters->filter !== []) {
            return ErrorResponse::fromException(new FilterParamUnrecognized((string) \array_key_first($queryParameters->filter)));
        }

        return ErrorResponse::fromException(new QueryParamUnrecognized($queryParameters->sort !== [] ? 'sort' : 'page'));
    }

    /**
     * Supplies the page-1 linkage and its pagination for a windowed to-many relationship
     * (linkage) endpoint OUT-OF-BAND through the request-scoped linkage/pagination seams, keyed
     * by the one rendered parent + relation (single-entry maps) — never a destructive write onto
     * the parent's relation property (which would corrupt a column-sharing sibling relation). A
     * paginated relation supplies its page so core emits the relationship object's
     * `first`/`prev`/`next`(/`last`) links in the spec's plain form; a relation with no paginator
     * is filtered/sorted but not sliced (no pagination entry).
     *
     * A cursor (keyset) window builds its page through the paginator's cursor path
     * ({@see CursorPaginator::fromBoundaries()}) off the provider-minted boundary tokens —
     * exactly as the related endpoint does — so the relationship links carry the real
     * `page[after]`/`page[before]` cursors (and never a `last`).
     *
     * Returns the supplied page (`null` when the relation carries no paginator or the fetch
     * was not windowed) so the caller can attach it to the {@see IdentifierResponse} — the
     * cursor page's profile is then advertised while the body stays links-only (core's docs/responses.md).
     *
     * @param list<object>             $items  the page-1 linkage members
     * @param CollectionResult<object> $result the provider's windowed fetch result
     *
     * @return PageInterface<mixed>|null
     */
    private function supplyWindowedRelationship(
        object $parent,
        string $relationshipName,
        array $items,
        ?PaginatorInterface $paginator,
        CollectionResult $result,
        bool $wantsCount,
        JsonApiRequestInterface $request,
        QueryParameters $queryParameters,
    ): ?PageInterface {
        $objectId = \spl_object_id($parent);
        $this->relationshipLinkage->set(new WindowedRelationshipLinkage(
            [$objectId => [$relationshipName => new RelationshipLinkage($items)]],
        ));

        if ($paginator === null || ($result->total === null && !$result->windowed)) {
            return null;
        }

        $queryString = (new RelatedQuery(
            $request->getSorting() === [] ? null : \implode(',', $request->getSorting()),
            $queryParameters->filter,
        ))->toPlainQueryString();

        $page = match (true) {
            $result instanceof CursorCollectionResult && $paginator instanceof CursorPaginator => $paginator->fromBoundaries(
                $request,
                $items,
                $result->cursorBefore ?? '',
                $result->cursorAfter ?? '',
                $result->hasMore,
                $result->hasPrevious,
            ),
            $wantsCount && $result->total !== null => $paginator->paginate($request, $items, $result->total),
            default => $paginator->paginateWithoutCount($request, $items, $result->hasMore),
        };

        $this->relationshipPagination->set(new WindowedRelationshipPagination(
            [$objectId => [$relationshipName => new RelationshipPagination($page, $queryString)]],
        ));

        return $page;
    }

    /**
     * Batch eager-loads the effective `?include` tree rooted at `$entities` so an included
     * relationship does not N+1, and installs the out-of-band linkage of every rendered to-many
     * whose related type has its own scoping provider (cleared with no request or no entities).
     *
     * @param list<object> $entities
     */
    private function preloadIncludes(Server $server, array $entities, string $type, ?JsonApiRequestInterface $request): void
    {
        $this->relationshipLinkage->resetScope();
        if ($request !== null && $entities !== []) {
            $this->markPreloaded($entities);
            $this->relationshipLinkage->scope($this->includeBatcher->preload($server, $entities, $type, $request));
        }
    }

    /**
     * A page of a polymorphic relation's members grouped by the type each renders as.
     *
     * @param list<object>       $items
     * @param array<int, string> $types the type a member renders as, keyed by object id, when its
     *                                  object can't say
     *
     * @return array<string, list<object>>
     */
    private function groupByRenderedType(Server $server, RelationInterface $relation, array $items, array $types = []): array
    {
        $byType = [];
        foreach ($items as $item) {
            $memberType = $types[\spl_object_id($item)] ?? $relation->resolveSerializer($item, $server)?->getType($item);
            if ($memberType !== null) {
                $byType[$memberType][] = $item;
            }
        }

        return $byType;
    }

    /**
     * Preloads primary resources of several types, keyed by type, keeping every type's
     * scoped linkage.
     *
     * @param array<string, list<object>> $groups
     */
    private function preloadGroups(Server $server, array $groups, JsonApiRequestInterface $request): void
    {
        $this->relationshipLinkage->resetScope();

        foreach ($groups as $type => $members) {
            if (!$this->providers->supportsType($type)) {
                continue;
            }

            $this->markPreloaded($members);
            $scoped = $this->includeBatcher->preload($server, $members, $type, $request);
            if ($scoped !== null) {
                $this->relationshipLinkage->addScope($scoped);
            }
        }
    }

    /**
     * @param list<object> $entities
     */
    private function markPreloaded(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->preloaded[\spl_object_id($entity)] = true;
        }
    }

    /**
     * Installs the per-render `?withCount` count seam for a read of `$type` over its fetched
     * `$items`, in ONE grouped count per named countable relation (no N+1), so core emits
     * `meta.total` on each relationship object the request named. Called on every read so it
     * also CLEARS the holder (installs `null`) when the request named no `?withCount`.
     *
     * @param list<object> $items
     */
    private function applyRelationshipCounts(Server $server, string $type, array $items, ?JsonApiRequestInterface $request): void
    {
        $this->relationshipCount->set(
            $request === null ? null : $this->countBatcher->batch($server, $type, $items, $request),
        );
    }

    /**
     * {@see applyRelationshipCounts()} for primary resources of several types: each type
     * counts the `?withCount` relations it declares over its own members, and the maps merge.
     *
     * @param array<string, list<object>> $groups
     */
    private function applyGroupedRelationshipCounts(Server $server, array $groups, JsonApiRequestInterface $request): void
    {
        $counts = [];
        foreach ($groups as $type => $members) {
            $counts[] = $this->countBatcher->batch($server, $type, $members, $request);
        }

        $this->relationshipCount->set(BatchedRelationshipCount::merge(...$counts));
    }

    /**
     * Installs the per-render relationship-window seam for a read of `$type` over its fetched
     * `$items` under the Relationship Queries profile: the {@see RelationshipWindowBatcher}
     * windows each rendered to-many relation to page 1 of its `relatedQuery`-ordered/filtered
     * set — on the Eloquent reference through the `groupLimit`/ROW_NUMBER SQL push-down
     * — and supplies that page-1 LINKAGE + relationship-object PAGINATION out-of-band
     * (NOT written back onto each parent), both maps swapped into the request-scoped holders the
     * memoized Server renders through, so core emits the windowed linkage and the relationship
     * object's pagination links in plain form while a column-sharing bystander relation still
     * renders its own membership.
     *
     * Called on every read (and write) so it also CLEARS the holders (installs `null`) when the
     * request did not negotiate the profile — a prior profile request's pages never leak into
     * this render. A no-op with no request to read the profile/relatedQuery from.
     *
     * @param list<object> $items the fetched page whose rendered to-many relations to window
     */
    /**
     * Returns the distinct profiles the windowed included pages activate (a
     * cursor-paginated included relation activates the cursor-pagination profile), so the
     * caller can surface them onto the document's applied set even when the primary page
     * does not carry them; empty when no included page activates a profile.
     *
     * @param list<object> $items
     *
     * @return list<\haddowg\JsonApi\Schema\Profile\ProfileInterface>
     */
    private function applyRelationshipWindows(Server $server, string $type, array $items, ?JsonApiRequestInterface $request): array
    {
        $result = $request === null ? null : $this->windowBatcher->batch($server, $type, $items, $request);

        $this->relationshipPagination->set($result?->pagination);
        $this->relationshipLinkage->set($result?->linkage);

        // A windowed page holds its own copies of the related members, so their nested
        // includes and linkage are walked again for any related type with its own scoping provider.
        if ($request !== null && $result?->linkage !== null) {
            $scoped = $this->includeBatcher->preloadWindowed($server, $type, $items, $result->linkage, $request);
            if ($scoped !== null) {
                $this->relationshipLinkage->addScope($scoped);
            }
        }

        return $result?->pagination?->activatedProfiles() ?? [];
    }

    /**
     * Loads the parent resource of a related / relationship read through the read provider,
     * or `null` when there is no id (the handler maps `null` to a `404`).
     */
    private function loadParent(string $type, ?string $id): ?object
    {
        if ($id === null) {
            return null;
        }

        return $this->providers->forType($type)->fetchOne($type, $id);
    }

    /**
     * Resolves the declared, non-hidden relation named `$name` on `$type`, or `null` when
     * the type declares no such relationship — the handler maps a `null` to a `404`.
     */
    private function resolveRelation(Server $server, string $type, string $name): ?RelationInterface
    {
        return $this->types->relationNamed($server, $type, $name);
    }

    /**
     * A `(type) => ?AbstractResource` resolver over the server, threaded into the linkage
     * validator so it can look up a related type's declared id format (the linkage-id-format
     * pass). Returns `null` for a type the server declares no resource for (a bare
     * serializer/hydrator pair — its id is then unconstrained).
     *
     * @return \Closure(string): ?AbstractResource
     */
    private function resourceResolver(Server $server): \Closure
    {
        return static fn(string $relatedType): ?AbstractResource => $server->hasResourceFor($relatedType)
            ? $server->resourceFor($relatedType)
            : null;
    }

    /**
     * Gates a related / relationship read (re-idiomized from the bundle's
     * event gate to a policy gate): the parent's `view` policy (the same gate the primary
     * single fetch applies) authorizes the loaded parent, UNLESS the relation declares its
     * own read security — a `false` opts the relation out of any read gate, a string
     * overrides the ability the parent is authorized against.
     */
    private function gateRead(string $type, object $parent, RelationInterface $relation, string $serverName): void
    {
        $read = $relation->securityRead();
        if ($read === false) {
            return;
        }

        // An explicit string ability, or else a `view{Relation}` ability the parent's policy or
        // the Gate defines, replaces the parent's `view`.
        $ability = \is_string($read) ? $read : ($read === null ? $this->authorizer->conventionReadAbility($type, $relation, $parent, $serverName) : null);
        if ($ability !== null) {
            $this->authorizer->authorizeAbility($type, $ability, $parent, $serverName);

            return;
        }

        $this->authorizer->authorize($type, Operation::FetchOne, $parent, $serverName);
    }

    /**
     * Refuses a related or relationship read of a to-many whose related type the caller may not
     * list, exactly as `GET /{type}` refuses them. A polymorphic to-many needs every
     * member type.
     */
    private function gateRelatedList(string $type, object $parent, RelationInterface $relation): void
    {
        // A read permission the relation declares replaces the related type's list gate, and
        // gateRead() has already enforced it.
        if ($this->viewableRows === null || !$relation->isToMany()
            || $this->viewableRows->relationPermission($parent, $relation, $type) !== null) {
            return;
        }

        foreach ($relation->relatedTypes() as $relatedType) {
            $this->viewableRows->authorizeList($relatedType);
        }
    }

    /**
     * A {@see PolymorphicSerializer} for the related page of a `MorphToMany` declared with
     * `identifiedBy()`: each row renders with the serializer of the type its pair named, and
     * the `meta.pivot` of its link row. Every related type is a member type, so `?withCount`
     * counts what any of them counts.
     *
     * @param array<int, string>               $types  each row's type, keyed by object id
     * @param array<int, array<string, mixed>> $pivots each row's `meta.pivot`, keyed by object id
     */
    private function identifiedSerializer(RelationInterface $relation, Server $server, array $types, array $pivots = []): PolymorphicSerializer
    {
        return new PolymorphicSerializer(
            static function (mixed $object) use ($relation, $server, $types, $pivots): SerializerInterface {
                if (!\is_object($object) || !isset($types[\spl_object_id($object)])) {
                    throw new \LogicException(\sprintf('No stored pair of the "%s" relationship names a related object.', $relation->name()));
                }

                $serializer = $server->serializerFor($types[\spl_object_id($object)]);
                $pivot = $pivots[\spl_object_id($object)] ?? [];

                return $pivot === [] ? $serializer : new PivotMetaSerializer($serializer, [$serializer->getId($object) => $pivot]);
            },
            PolymorphicSerializer::forRelation($relation, $server)->members(),
        );
    }

    /**
     * The parent type's provider when it queries an identified `MorphToMany`'s link rows itself.
     */
    private function linkRows(string $type): ?LinkRowQueryInterface
    {
        if (!$this->providers->supportsType($type)) {
            return null;
        }

        $provider = $this->providers->forType($type);

        return $provider instanceof LinkRowQueryInterface ? $provider : null;
    }

    /**
     * A {@see PolymorphicSerializer} that renders a polymorphic to-many's mixed-type
     * members, each through the serializer of its own declared type. Every related type is
     * a member type, so a `?withCount` name any of them counts is accepted.
     */
    private function polymorphicSerializer(RelationInterface $relation, Server $server): PolymorphicSerializer
    {
        return PolymorphicSerializer::forRelation($relation, $server);
    }

    /**
     * The parent-type serializer for a relationship-linkage render ({@see fetchRelationship()}
     * and the pivot mutation echo), decorated so a `belongsToMany` pivot relation's linkage
     * identifiers carry their stored pivot values as `meta.pivot`. A non-pivot
     * relation (or a provider that stores no pivot — the in-memory witness) renders through the
     * bare parent serializer unchanged, so `meta.pivot` is Eloquent-only.
     *
     * A windowed render passes the pivot map of its page; otherwise the map covers the whole
     * association.
     *
     * @param array<string, array<string, mixed>>|null $pivotMap
     */
    private function relationshipLinkageSerializer(
        Server $server,
        string $type,
        object $parent,
        RelationInterface $relation,
        string $relationshipName,
        ?array $pivotMap = null,
    ): SerializerInterface {
        $serializer = $server->serializerFor($type);

        $pivotMap ??= $this->pivotMap($server, $type, $parent, $relation);
        if (!$this->rendersPivotMeta($relation, $pivotMap)) {
            return $serializer;
        }

        $relatedType = $relation->relatedTypes()[0] ?? $type;

        return new PivotParentSerializer(
            $serializer,
            $relationshipName,
            $relation,
            $server,
            new PivotMetaSerializer($server->serializerFor($relatedType), $pivotMap, $relation instanceof BelongsToMany ? $relation : null, $parent, $server->outputTimezone()),
        );
    }

    /**
     * Whether a relation's members carry `meta.pivot`: the provider stored some, or the
     * relation declares computed pivot fields, which render on any provider.
     *
     * @param array<string, array<string, mixed>> $pivotMap
     */
    private function rendersPivotMeta(RelationInterface $relation, array $pivotMap): bool
    {
        return $pivotMap !== [] || ($relation instanceof BelongsToMany && $relation->computedPivotFields() !== []);
    }

    /**
     * The stored per-member pivot map (`relatedId => [field => value]`) for a `belongsToMany`
     * relation declaring stored pivot fields, read through the parent's provider seam
     * ({@see \haddowg\JsonApiLaravel\DataProvider\DataProviderInterface::fetchRelationshipPivot()}),
     * or `[]` when the relation is not a pivot relation / declares no pivot fields / the
     * provider stores none (the in-memory boundary — pivot READ is Eloquent-only).
     *
     * Given the fetched `$members` of a page, a related provider that queries the pivot join
     * ({@see QueriesPivotColumnsInterface}) supplies the map off those members with no further
     * query; otherwise the parent's provider reads the whole association's pivot rows.
     *
     * A pivot value the provider returns as a `\DateTimeInterface` renders through its
     * `DateTime`, `Date` or `Time` field, in the field's format and, for a `DateTime`, in
     * the field's own `outputTimezone()` or else the server's. Every other value
     * renders as the provider returned it.
     *
     * @param list<object>|null $members
     *
     * @return array<string, array<string, mixed>>
     */
    private function pivotMap(Server $server, string $type, object $parent, RelationInterface $relation, ?array $members = null): array
    {
        if (!$relation instanceof BelongsToMany || $relation->storedPivotFields() === []) {
            return [];
        }

        $pivotProvider = $members !== null ? $this->pivotQueryingProvider($parent, $relation) : null;
        $pivots = $pivotProvider !== null && $members !== null
            ? $pivotProvider->pivotMapOf($parent, $relation, $members)
            : $this->providers->forType($type)->fetchRelationshipPivot($type, $parent, $relation);

        $dates = [];
        foreach ($relation->pivotFields() as $field) {
            if ($server->outputTimezone() !== null && $field instanceof AcceptsDefaultOutputTimezoneInterface) {
                $field = $field->withDefaultOutputTimezone($server->outputTimezone());
            }
            if ($field instanceof DateTime) {
                $dates[$field->name()] = $field;
            }
        }

        if ($dates === []) {
            return $pivots;
        }

        foreach ($pivots as $id => $values) {
            foreach ($dates as $name => $field) {
                $value = $values[$name] ?? null;
                if ($value instanceof \DateTimeInterface) {
                    $pivots[$id][$name] = $field->serializeWithoutRequest([$field->column() ?? $name => $value]);
                }
            }
        }

        return $pivots;
    }

    /**
     * The related type's provider when it filters, sorts and reads `meta.pivot` over
     * `$relation`'s pivot join for this parent, else `null`.
     */
    private function pivotQueryingProvider(object $parent, RelationInterface $relation): ?QueriesPivotColumnsInterface
    {
        $relatedTypes = $relation->relatedTypes();
        if (!Polymorphism::isMonomorphic($relation) || !$this->providers->supportsType($relatedTypes[0])) {
            return null;
        }

        $provider = $this->providers->forType($relatedTypes[0]);

        return $provider instanceof QueriesPivotColumnsInterface && $provider->queriesPivotColumns($parent, $relation)
            ? $provider
            : null;
    }

    /**
     * `POST /{type}` — create a single resource. Order mirrors the bundle's `create()`
     * arm: the document-validation and authorization seams sit before hydrate/persist.
     */
    private function create(CreateResourceOperation $operation): DataResponse|AcceptedResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $type = $operation->target()->type;
        $body = $operation->body();
        $request = $this->jsonApiRequest($operation->context());

        $persister = $this->persisters->forType($type);
        $serializer = $server->serializerFor($type);
        $resource = $server->hasResourceFor($type) ? $server->resourceFor($type) : null;

        // The blank instance is minted first so the document validator can resolve the
        // owning Eloquent table for a UniqueEntity `Rule::unique` (pre-hydration);
        // it stays the target the hydrator populates below.
        $entity = $persister->instantiate($type);

        // The response renders the created resource, so its `relatedQuery` is checked before
        // anything is stored.
        $this->windowBatcher->check($server, $type, $entity, $request);

        // Document-semantic validation (the always-on illuminate/validation bridge) runs
        // here, creating: true, before hydration — a `422` document-first, before the
        // `create` policy gate below, so validation precedes authorization (bundle
        // parity: 422 before 403).
        if ($resource !== null) {
            ReadOnlyAttributes::reject($server, $resource, $request, true);
            $this->validator->validate($resource, $request, true, subject: $entity);
        }

        // The `create` policy gate authorizes the type here, before hydration —
        // validate-before-authorize preserves the bundle's 422-before-403. Create carries
        // no instance to the policy method, so the blank instance's class-string is used
        // (a policy's `create($user)`). The pristine-subject / authorize-before-hydrate
        // timing (and its entity-seam-422-after-403 + uniqueness-oracle consequences) is a
        // recorded divergence from the bundle — see ADR 0002.
        $serverName = $this->serverNameOf($operation->context());
        $this->authorizer->authorize($type, Operation::Create, $entity, $serverName);

        // Whole-resource writes embedding `data.relationships`: core's hydrator
        // would assign a scalar linkage id to a typed association property (a TypeError /
        // NOT-NULL 500), so the relationships are stripped from the body before hydration
        // and set through the persister's relationship seam instead — a full replacement,
        // the flush deferred to the single create() commit. Each embedded
        // linkage is validated with the same pass the dedicated endpoint uses (a `409`
        // resource-type conflict / `422` malformed linkage or pivot meta), before persist.
        // A to-one (owner-side FK) applies inline before the create; a to-many (join /
        // inverse FK) is DEFERRED to after the parent is keyed — an Eloquent belongsToMany
        // join insert needs the parent PK, a reference-storage divergence from the bundle's
        // uniform pre-create apply (ADR 0005). The `cannot*` gate is NOT applied on a
        // create (there is nothing to replace; a create sets the initial state).
        $relationships = $this->extractRelationships($server, $type, $body, true);
        $resolveResource = $this->resourceResolver($server);
        foreach ($relationships as $relationship) {
            $this->validator->validateRelationshipLinkage(
                $relationship['relation'],
                $relationship['linkage'],
                Mode::Replace,
                [],
                $body,
                embeddedRelationName: $relationship['relation']->name(),
                resolveResource: $resolveResource,
            );
        }
        $this->gateEmbeddedMutations($type, $entity, $relationships, $serverName);
        [$beforeCreate, $deferred] = $this->partitionForCreateOrder($relationships);
        $entity = $this->applyRelationships($persister, $type, $entity, $beforeCreate, $body, true, $server, $request, flush: false);

        // Core's hydrator applies the id policy (client vs server-generated; a wrong
        // `type` 409s, a forbidden client id 403s) then allow-list-hydrates the
        // attributes; the throws propagate to the exception renderer. The
        // relationships are stripped so core never scalar-hydrates an association.
        $entity = $server->hydratorFor($type)->hydrate($this->datesInStorageZone($resource, $entity, $this->withoutRelationships($body)), $entity);
        \assert(\is_object($entity));
        $this->unionsInStoredForm($resource, $entity, $body, true);

        // The post-hydration entity seam (uniqueness's custom cousin): a resource's
        // EntityConstraintInterface constraints validate the hydrated entity before
        // persist. A no-op for a resource that declares none (UniqueEntity is folded into
        // the pre-hydration Rule::unique above).
        if ($resource !== null) {
            $this->validator->validateEntity($resource, $entity, true);
        }

        // Before-save then before-create lifecycle gates: a listener/hook may mutate the
        // entity (persisted by the commit below) or throw to abort — the throw propagates
        // to the exception renderer, so the persister never runs and nothing commits.
        $this->dispatch(new BeforeSaveEvent($type, $request, $entity, true, $this->serverName($request)));
        $this->dispatch(new BeforeCreateEvent($type, $request, $entity, $this->serverName($request)));

        // The create() and the deferred (join / inverse-FK) relationship applies commit as
        // ONE unit: create() opens its own transaction, which nests as a savepoint under this
        // outer boundary, so a failing deferred apply (a QueryException on a join insert, an FK
        // violation) rolls the parent row back too — no orphaned, partially-related resource.
        // This restores the atomicity the bundle's single flush guarantees, despite the
        // Eloquent PK-before-join reordering; a non-transactional persister
        // runs the closure directly (it owns its own atomicity).
        $entity = $this->writeTransactionally($persister, function () use ($persister, $type, $entity, $deferred, $body, $server, $request): object {
            // The persister commits and returns the entity with any store-generated id
            // populated (Eloquent auto-increment / the in-memory store's minted id). The
            // deferred embeds then apply now the parent carries its key (an Eloquent join
            // insert / inverse-FK reparent both need the parent PK), their per-op transactions
            // nesting as savepoints under this boundary.
            $created = $persister->create($type, $entity);

            // The persister accepted the write for asynchronous processing (it dispatched
            // the work instead of committing) — nothing was persisted, so the deferred
            // (join / inverse-FK) applies have no keyed parent to hang off and are skipped;
            // the queued job owns the whole write, relationships included (ADR 0009).
            if ($created instanceof AcceptedForProcessing) {
                return $created;
            }

            $this->applyRelationships($persister, $type, $created, $deferred, $body, true, $server, $request, flush: true);
            $this->writeLinkRows($server, $created, $request);

            return $created;
        });

        // Render the async accept as a `202 Accepted` pointing at the job resource, not
        // the `201` a synchronous create renders (as the bundle does). The
        // post-commit include/window/count wiring and the After* hooks below do not apply
        // (no resource was persisted), so the branch returns here.
        if ($entity instanceof AcceptedForProcessing) {
            return $this->accepted($entity, $server);
        }

        // A write response is the same resource document, so it honours `?include`, the
        // Relationship Queries profile (windowed linkage/pagination), and `?withCount` exactly
        // as a read does — preload → window → count over the created entity, before rendering.
        $this->preloadIncludes($server, [$entity], $type, $request);
        $this->applyRelationshipWindows($server, $type, [$entity], $request);
        $this->applyRelationshipCounts($server, $type, [$entity], $request);

        // The Location uses the resource's URI segment (its uriType), so it matches the
        // route a client GETs; a bare pair falls back to the type. It is resolved from
        // the request the SAME way the body's links are, so the Location and the created
        // resource's own `links.self` stay equal (core's docs/links-and-meta.md).
        $uriType = $server->hasResourceFor($type) ? $server->resourceFor($type)->uriType() : $type;
        $baseUri = RequestBaseUri::resolve($server->baseUri(), $request->getUri());

        $response = DataResponse::fromResource($entity, $serializer)
            ->withStatus(201)
            ->withHeader('Location', $baseUri . '/' . $uriType . '/' . $serializer->getId($entity));

        // After-create then after-save lifecycle hooks (post-commit) may replace the
        // 201; after-save fires last, so it has the final word. Under an active Atomic
        // Operations batch the dispatch is deferred to post-commit (the replacement is
        // inert — the aggregate result wins); on the single-op path it fires inline.
        return $this->deferOrFire(function () use ($type, $request, $entity, $response): DataResponse {
            $afterCreate = new AfterCreateEvent($type, $request, $entity, $this->serverName($request));
            $this->dispatch($afterCreate);
            $response = $afterCreate->response() ?? $response;

            $afterSave = new AfterSaveEvent($type, $request, $entity, true, $this->serverName($request));
            $this->dispatch($afterSave);

            return $afterSave->response() ?? $response;
        }, $response);
    }

    /**
     * `PATCH /{type}/{id}` — update a single resource. A missing target is a `404`
     * (returned, not thrown); the incoming partial is allow-list-hydrated onto the
     * loaded target and committed, rendering `200` with the updated document (an
     * unsupplied attribute is left untouched).
     */
    private function update(UpdateResourceOperation $operation): DataResponse|AcceptedResponse|ErrorResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $type = $operation->target()->type;
        $id = $operation->target()->id;
        $body = $operation->body();
        $request = $this->jsonApiRequest($operation->context());

        $provider = $this->providers->forType($type);
        $entity = $id !== null ? $provider->fetchOne($type, $id) : null;
        if ($entity === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        // A shallow clone of the loaded target taken before hydration, so a
        // before-update hook can diff the incoming change against the prior state.
        $original = clone $entity;

        $persister = $this->persisters->forType($type);
        $serializer = $server->serializerFor($type);
        $resource = $server->hasResourceFor($type) ? $server->resourceFor($type) : null;

        // The `update` policy gate authorizes the stored target first, before validation and
        // before anything is applied, so ownership is decided on stored state and a caller it
        // refuses learns nothing from validation errors. It runs again on the changed
        // resource before the save below.
        $serverName = $this->serverNameOf($operation->context());
        $this->authorizer->authorize($type, Operation::Update, $entity, $serverName);
        $this->windowBatcher->check($server, $type, $entity, $request);

        // Document-semantic validation runs here, creating: false, with the loaded target
        // so a PATCH validates the MERGED resource state (stored values overlaid by the
        // incoming partial), not the partial alone — and the loaded
        // model supplies the UniqueEntity `Rule::unique` table + self-ignore id.
        if ($resource !== null) {
            ReadOnlyAttributes::reject($server, $resource, $request, false);
            $this->validator->validate($resource, $request, false, existingObject: $entity, subject: $entity);
        }

        // Whole-resource writes embedding `data.relationships`: stripped from the
        // body before hydration and set through the persister's relationship seam, a full
        // replacement per named association (never an incremental
        // add/remove). Each is validated with the endpoint's pass — folding the loaded
        // parent's stored pivot rows in for the merge-before-validate (an existing member's
        // omitted required pivot field keeps its stored value, no false 422). The apply is
        // deferred (`flush: false`) so the single update() commit below owns the flush.
        $relationships = $this->extractRelationships($server, $type, $body, false);
        $existingPivots = $this->existingPivots($server, $type, $body, $entity);
        $resolveResource = $this->resourceResolver($server);
        foreach ($relationships as $relationship) {
            $pivot = $existingPivots[$relationship['relation']->name()] ?? [];
            $this->validator->validateRelationshipLinkage(
                $relationship['relation'],
                $relationship['linkage'],
                Mode::Replace,
                $pivot,
                $body,
                embeddedRelationName: $relationship['relation']->name(),
                resolveResource: $resolveResource,
            );
        }
        $this->gateEmbeddedMutations($type, $entity, $relationships, $serverName);

        // Hydration mutates the loaded target in place (the in-memory witness returns the
        // stored instance by reference), so a mid-hydration throw or a post-hydration
        // validateEntity 422 would otherwise leave a partial write visible to reads. Wrap
        // the mutating tail in the persister's transaction so a failure rolls the store
        // back to its pre-hydration state — the in-memory analogue of Eloquent's
        // rollback-on-throw, keeping the two providers' failed-write semantics identical.
        $hydrator = $server->hydratorFor($type);
        $entity = $this->writeTransactionally($persister, function () use ($hydrator, $body, $resource, $type, $persister, $entity, $original, $request, $relationships, $server, $serverName): object {
            // The embedded associations apply on the loaded (keyed) parent BEFORE hydration,
            // each gated by the relation's request-aware `cannot*` flags in Mode::Replace —
            // the same `403` a PATCH …/relationships/{rel} would raise.
            $entity = $this->applyRelationships($persister, $type, $entity, $relationships, $body, false, $server, $request, flush: false);

            $entity = $hydrator->hydrate($this->datesInStorageZone($resource, $entity, $this->withoutRelationships($body)), $entity);
            \assert(\is_object($entity));
            $this->unionsInStoredForm($resource, $entity, $body, false);

            if ($resource !== null) {
                $this->validator->validateEntity($resource, $entity, false);
            }

            // Before-save then before-update lifecycle gates (the entity is mutable,
            // `$original` is the pre-change snapshot): a throw aborts before the persister
            // commits — inside this savepoint boundary, so writeTransactionally rolls back.
            $this->dispatch(new BeforeSaveEvent($type, $request, $entity, false, $this->serverName($request)));
            $this->dispatch(new BeforeUpdateEvent($type, $request, $entity, $original, $this->serverName($request)));

            // The same gates again on the resource as changed, before it is saved, so a caller
            // can't turn it into one they couldn't update, such as handing it to another user.
            // A denial rolls back everything applied above.
            $this->authorizer->authorize($type, Operation::Update, $entity, $serverName);
            $this->gateEmbeddedMutations($type, $entity, $relationships, $serverName);

            $updated = $persister->update($type, $entity);
            if (!$updated instanceof AcceptedForProcessing) {
                $this->writeLinkRows($server, $updated, $request);
            }

            return $updated;
        });

        // The persister accepted the update for asynchronous processing — render a
        // `202 Accepted` pointing at the job resource, as the async create does; the
        // post-commit wiring and After* hooks below do not apply.
        if ($entity instanceof AcceptedForProcessing) {
            return $this->accepted($entity, $server);
        }

        // A write response honours `?include`, the Relationship Queries profile (windowed
        // linkage/pagination), and `?withCount` exactly as a read does — preload → window →
        // count over the updated entity, before rendering.
        $this->preloadIncludes($server, [$entity], $type, $request);
        $this->applyRelationshipWindows($server, $type, [$entity], $request);
        $this->applyRelationshipCounts($server, $type, [$entity], $request);

        $response = DataResponse::fromResource($entity, $serializer);

        // After-update then after-save lifecycle hooks (post-commit) may replace the 200;
        // after-save fires last. Deferred to post-commit under an active Atomic Operations
        // batch (replacement inert); fired inline on the single-op path.
        return $this->deferOrFire(function () use ($type, $request, $entity, $response): DataResponse {
            $afterUpdate = new AfterUpdateEvent($type, $request, $entity, $this->serverName($request));
            $this->dispatch($afterUpdate);
            $response = $afterUpdate->response() ?? $response;

            $afterSave = new AfterSaveEvent($type, $request, $entity, false, $this->serverName($request));
            $this->dispatch($afterSave);

            return $afterSave->response() ?? $response;
        }, $response);
    }

    /**
     * Renders a persister's async-accept marker ({@see AcceptedForProcessing}) as
     * core's `202` {@see AcceptedResponse}: the pollable job resource (through the job
     * type's registered serializer) or a meta-only status document, carrying the
     * `Content-Location` and any `Retry-After` the persister set. An async accept
     * cannot participate in an all-or-nothing Atomic Operations batch — it defers the
     * write past the batch's commit — so it is refused (`422`, rolling the batch back)
     * when a batch is in flight.
     */
    private function accepted(AcceptedForProcessing $accepted, Server $server): AcceptedResponse
    {
        if ($this->txContext?->isActive() ?? false) {
            throw new AsyncWriteNotAllowedInAtomicOperation();
        }

        $job = $accepted->job();
        $jobType = $accepted->jobType();

        $response = $job !== null && $jobType !== null
            ? AcceptedResponse::forResource($job, $server->serializerFor($jobType))
            : AcceptedResponse::fromMeta($accepted->meta());

        $response = $response->withContentLocation($accepted->contentLocation());

        $retryAfter = $accepted->retryAfter();
        if ($retryAfter !== null) {
            $response = $response->withRetryAfter($retryAfter);
        }

        return $response;
    }

    /**
     * `DELETE /{type}/{id}` — delete a single resource. A missing target is a `404`
     * (returned, not thrown); the loaded target is removed and the response is `204`
     * with no body.
     */
    private function delete(DeleteResourceOperation $operation): DataResponse|NoContentResponse|ErrorResponse
    {
        $type = $operation->target()->type;
        $id = $operation->target()->id;
        $request = $this->jsonApiRequest($operation->context());

        $entity = $id !== null ? $this->providers->forType($type)->fetchOne($type, $id) : null;
        if ($entity === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        // The `delete` policy gate authorizes the loaded target here, before it is
        // removed.
        $this->authorizer->authorize($type, Operation::Delete, $entity, $this->serverNameOf($operation->context()));

        // The before-delete lifecycle gate (a throw aborts before the persister deletes
        // — a delete guard's natural seam, e.g. a 409 when the resource is referenced).
        $this->dispatch(new BeforeDeleteEvent($type, $request, $entity, $this->serverName($request)));

        $this->persisters->forType($type)->delete($type, $entity);

        $response = NoContentResponse::create();

        // The after-delete lifecycle hook (post-commit) may replace the 204 — e.g. a
        // soft-delete that renders the now-flagged resource. Deferred to post-commit
        // under an active Atomic Operations batch (replacement inert); fired inline on
        // the single-op path.
        return $this->deferOrFire(function () use ($type, $request, $entity, $response): DataResponse|NoContentResponse {
            $afterDelete = new AfterDeleteEvent($type, $request, $entity, $this->serverName($request));
            $this->dispatch($afterDelete);

            return $afterDelete->response() ?? $response;
        }, $response);
    }

    /**
     * `PATCH`/`POST`/`DELETE /{type}/{id}/relationships/{relationship}` — the relationship
     * mutation arms (full-replacement / add / remove), one shape (the Laravel twin of the
     * bundle's `mutateRelationship`, re-idiomised to a policy gate + the Eloquent persister):
     *  1. load the parent through the read provider (a `404` when absent);
     *  2. resolve the named relation (a `404` {@see RelationshipNotExists} when unknown or its
     *     relationship endpoint is suppressed);
     *  3. cardinality gate — an add/remove to a to-one is a `400`
     *     {@see RelationshipTypeInappropriate};
     *  4. parse the linkage with core's relationship-endpoint body parser;
     *  5. {@see guardMutability()} — the relation's request-aware mutability flags
     *     ({@see FullReplacementProhibited}/{@see AdditionProhibited}/{@see RemovalProhibited},
     *     `403`);
     *  6. validate the linkage — a `409` resource-type conflict / `422` malformed linkage;
     *  7. {@see gateMutate()} — the policy gate: the parent's `update`
     *     policy, or the relation's `securityMutate` ability override;
     *  8. apply through the persister's storage-owning relationship seam inside a transaction,
     *     validate the changed parent against its entity-level constraints and run the
     *     policy gate again on it before it commits, and
     *     render the resulting linkage ({@see IdentifierResponse::forRelationship()},
     *     `200` — the spec allows `200`/`204`; the bundle returns `200`, matched here).
     *
     * The windowed linkage + pivot `meta.pivot` echo ride the Relationship
     * Queries profile / pivot machinery.
     */
    private function mutateRelationship(
        AddToRelationshipOperation|UpdateRelationshipOperation|RemoveFromRelationshipOperation $operation,
        JsonApiRequestInterface $body,
        Mode $mode,
    ): IdentifierResponse|ErrorResponse {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $target = $operation->target();
        $type = $target->type;
        $relationshipName = (string) $target->relationship;

        $parent = $this->loadParent($type, $target->id);
        if ($parent === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $relation = $this->resolveRelation($server, $type, $relationshipName);
        if ($relation === null || !$relation->exposesRelationshipEndpoint()) {
            return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
        }

        // Add / remove are to-many operations: a POST / DELETE to a to-one relationship
        // endpoint is a cardinality error (400).
        if ($mode !== Mode::Replace && $relation->isToMany() === false) {
            throw new RelationshipTypeInappropriate($relationshipName, 'to-one', 'to-many');
        }

        $linkage = $relation->isToMany()
            ? $body->getRelationshipDataToMany($relationshipName)
            : $body->getRelationshipDataToOne($relationshipName);

        $request = $this->jsonApiRequest($operation->context());

        // The relation's request-aware mutability flags (cannotReplace/cannotAdd/cannotRemove/
        // cannotChangeMembership) — a 403 family, distinct from the policy gate below.
        $this->guardMutability($relation, $relationshipName, $linkage, $mode, $body, $parent);
        if ($mode === Mode::Replace) {
            $this->guardMembership($relation, $linkage, $body, $parent, $server, $request);
        }

        // A pivot relation folds its stored pivot rows in for the merge-before-validate pass:
        // a member already in the relationship validates its MERGED pivot (stored row overlaid
        // by the incoming meta) in the update context, a genuinely-new member its incoming meta
        // in the create context. Eloquent supplies the real map; the in-memory witness returns
        // none (every member is then new). A Remove carries no pivot meta.
        $existingPivot = match (true) {
            $mode === Mode::Remove => [],
            $relation instanceof BelongsToMany && $relation->pivotFields() !== [] => $this->providers->forType($type)->fetchRelationshipPivot($type, $parent, $relation),
            IdentifiedMorphMembers::identifies($relation) && $relation->pivotFields() !== [] => $this->identifiedPivots($server, $parent, $relation, $body),
            default => [],
        };

        // A linkage carrying an unacceptable resource type is a 409 (the linkage twin of a
        // create's wrong data.type); a malformed linkage id / pivot meta a 422 — validated
        // before the persister applies, so a bad linkage never reaches storage. The endpoint
        // surface leaves `embeddedRelationName` null (pointers root at `/data/…`); the resolver
        // enables the linkage-id-format pass.
        $this->validator->validateRelationshipLinkage($relation, $linkage, $mode, $existingPivot, $body, resolveResource: $this->resourceResolver($server));

        // The policy gate (NEW vs the bundle's event gate): a relationship
        // mutation authorizes the parent — `update` by default, or the relation's own
        // `securityMutate` ability override — after validation (422/409 before the policy 403).
        $serverName = $this->serverNameOf($operation->context());
        $this->gateMutate($type, $parent, $relation, $serverName);

        // Ids the related type's own provider does not serve are rejected, and a replace keeps
        // the members it hides. The echo then lists the members the relationship endpoint
        // would, so a scoped to-many that cannot be read that way is refused before the write.
        $applied = $this->scopeWrittenLinkage($parent, $relation, $linkage, $mode, $server, $request);
        $this->guardReparenting($type, $parent, $relation, $applied, $mode, $serverName);
        // A write-only relation echoes nothing (core answers 204), so there is no linkage to
        // read back and no pivot map to fetch.
        $writeOnly = $relation->isWriteOnlyFor($request);
        $scopeEcho = !$writeOnly && $relation->isToMany() && $this->providers->relatesToScopedType($type, $relation);

        // The before-relationship-mutate lifecycle gate (a throw aborts before the
        // persister applies the change), then the storage-correct apply, then the after
        // hook which may replace the linkage response (post-commit).
        $this->dispatch(new BeforeRelationshipMutateEvent($type, $request, $parent, $relation, $linkage, $mode, $this->serverName($request)));

        // The changed parent must still pass its entity-level constraints, and the mutation gate
        // runs again on it, before the change commits, so a caller can't leave the parent in a
        // state that is invalid or that they couldn't update. Either failure rolls it back.
        $persister = $this->persisters->forType($type);
        $resource = $server->hasResourceFor($type) ? $server->resourceFor($type) : null;
        $parent = $this->writeTransactionally($persister, function () use ($persister, $type, $parent, $relation, $applied, $mode, $serverName, $resource, $server, $request): object {
            $this->queueLinkRowWrite($server, $parent, $relation, $applied, $mode, $request);
            $changed = match (true) {
                $this->declaresLinkRows($relation) => $this->writeDeclaredLinkRows($server, $persister, $type, $parent, $relation, $applied, $mode, $request, flush: true),
                RelationFillHook::declaredBy($relation) => $persister->update($type, RelationFillHook::apply($relation, $parent, $applied, $mode)),
                default => $persister->mutateRelationship($type, $parent, $relation, $applied, $mode),
            };
            $this->writeLinkRows($server, $changed, $request);
            if ($resource !== null) {
                $this->validator->validateEntity($resource, $changed, false);
            }
            $this->gateMutate($type, $changed, $relation, $serverName);

            return $changed;
        });
        if ($scopeEcho) {
            $this->scopeRelationshipEndpoint($server, $type, $parent, $relation, $request);
        }

        // The 200 linkage echo carries `meta.pivot` for a pivot relation (Eloquent-only),
        // exactly as the relationship-read endpoint does.
        $response = IdentifierResponse::forRelationship(
            $parent,
            $writeOnly
                ? $server->serializerFor($type)
                : $this->relationshipLinkageSerializer($server, $type, $parent, $relation, $relationshipName),
            $relationshipName,
        );

        // The after-relationship-mutate lifecycle hook (post-commit) may replace the
        // linkage response. Deferred to post-commit under an active Atomic Operations
        // batch (replacement inert); fired inline on the single-op path.
        return $this->deferOrFire(function () use ($type, $request, $parent, $relation, $linkage, $mode, $response): IdentifierResponse {
            $afterMutate = new AfterRelationshipMutateEvent($type, $request, $parent, $relation, $linkage, $mode, $this->serverName($request));
            $this->dispatch($afterMutate);

            return $afterMutate->response() ?? $response;
        }, $response);
    }

    /**
     * Enforces the relation's request-aware mutability flags for the requested mutation,
     * throwing core's typed `403`s (each gate resolves its `cannotReplace/
     * cannotAdd/cannotRemove` closure against the inbound `$body` + `$parent`, so "only admins
     * may replace this relationship" is enforced HERE, distinct from the policy gate):
     *  - a to-one clear (`data: null`) is a removal ({@see RemovalProhibited}); a non-null
     *    to-one `PATCH` is a replacement ({@see FullReplacementProhibited});
     *  - a to-many `PATCH` is a replacement ({@see FullReplacementProhibited}); a `POST` add
     *    is gated by `allowsAddFor` ({@see AdditionProhibited}); a `DELETE` remove by
     *    `allowsRemoveFor` ({@see RemovalProhibited}).
     */
    private function guardMutability(
        RelationInterface $relation,
        string $relationshipName,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        JsonApiRequestInterface $body,
        object $parent,
    ): void {
        if ($linkage instanceof ToOneRelationship) {
            if ($linkage->isEmpty()) {
                if ($relation->allowsRemoveFor($body, $parent) === false) {
                    throw new RemovalProhibited($relationshipName);
                }

                return;
            }

            if ($relation->allowsReplaceFor($body, $parent) === false) {
                throw new FullReplacementProhibited($relationshipName);
            }

            return;
        }

        if ($mode === Mode::Replace && $relation->allowsReplaceFor($body, $parent) === false) {
            throw new FullReplacementProhibited($relationshipName);
        }

        if ($mode === Mode::Add && $relation->allowsAddFor($body, $parent) === false) {
            throw new AdditionProhibited($relationshipName);
        }

        if ($mode === Mode::Remove && $relation->allowsRemoveFor($body, $parent) === false) {
            throw new RemovalProhibited($relationshipName);
        }
    }

    /**
     * Refuses a to-many replace that changes the members of a relation declared with
     * `cannotChangeMembership()` ({@see \haddowg\JsonApi\Exception\MembershipChangeProhibited},
     * `403`). The persister writes the association itself, so core's own check never runs. The
     * members a scope hides are added to the client's linkage, as the write will add them, so
     * the comparison is with everything the parent holds.
     */
    private function guardMembership(
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        JsonApiRequestInterface $body,
        object $parent,
        Server $server,
        JsonApiRequestInterface $request,
    ): void {
        if (!$linkage instanceof ToManyRelationship || !$relation instanceof GuardsMembershipInterface || $relation->allowsMembershipChangeFor($body, $parent)) {
            return;
        }

        $this->scopedWrites ??= new ScopedRelationshipWrites($this->providers, $this->relationshipLinkage->members());
        $relation->assertMembershipUnchanged(
            $body,
            $parent,
            $this->currentMembers($relation, $parent, $server, $request),
            $this->scopedWrites->withHiddenMembers($parent, $relation, $linkage, $server, $request),
        );
    }

    /**
     * The members `$relation` holds on `$parent`, as wire `[type, id]` pairs: an identified
     * `MorphToMany`'s stored pairs, otherwise the relation's value read off a copy of the parent
     * (so a relation an Eloquent model loads doesn't stay on the parent the response renders),
     * each member's type and id taken from its serializer.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function currentMembers(RelationInterface $relation, object $parent, Server $server, JsonApiRequestInterface $request): array
    {
        if (IdentifiedMorphMembers::identifies($relation)) {
            return $this->relationshipLinkage->members()->wirePairs($server, $parent, $relation, $request);
        }

        $value = $relation->readValue(clone $parent, $request);
        $members = [];
        foreach (\is_iterable($value) ? $value : [] as $member) {
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
     * Gates a relationship mutation: the parent's `update` policy
     * authorizes the loaded parent, UNLESS the relation declares its own mutate security —
     * a `false` opts the relation out of any mutate gate, a string overrides the ability the
     * parent is authorized against (the `securityMutate` twin of {@see gateRead()}'s
     * `securityRead`). A denial throws {@see \Illuminate\Auth\Access\AuthorizationException}
     * (rendered `403`).
     */
    private function gateMutate(string $type, object $parent, RelationInterface $relation, string $serverName): void
    {
        $mutate = $relation->securityMutate();
        if ($mutate === false) {
            return;
        }

        if (\is_string($mutate)) {
            $this->authorizer->authorizeAbility($type, $mutate, $parent, $serverName);

            return;
        }

        $this->authorizer->authorize($type, Operation::Update, $parent, $serverName);
    }

    /**
     * Applies each embedded relation's own `securityMutate` ability to the parent, as the
     * relationship endpoint does. A relation without a declared ability is already covered by
     * the create/update gate.
     *
     * @param list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}> $relationships
     */
    private function gateEmbeddedMutations(string $type, object $parent, array $relationships, string $serverName): void
    {
        foreach ($relationships as $relationship) {
            $mutate = $relationship['relation']->securityMutate();
            if (\is_string($mutate)) {
                $this->authorizer->authorizeAbility($type, $mutate, $parent, $serverName);
            }
        }
    }

    /**
     * Refuses to move a related row away from another parent unless the client may update
     * that parent, or the row itself (ADR 0017), and refuses to move a row no parent holds
     * unless the client may update the row. Only relations where a row has one
     * parent (an inverse foreign key) report owners; a Remove never moves a row.
     */
    private function guardReparenting(string $type, object $parent, RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, Mode $mode, string $serverName): void
    {
        $persister = $this->persisters->forType($type);
        // A fill hook decides where the linkage lands, so there is no stored owner to read.
        if ($mode === Mode::Remove || !$persister instanceof ReportsCurrentOwners || RelationFillHook::declaredBy($relation) || $this->declaresLinkRows($relation)) {
            return;
        }

        foreach ($persister->currentOwners($type, $parent, $relation, $linkage) as $held) {
            if ($held['owner'] !== null && $held['ownerType'] !== null) {
                try {
                    $this->authorizer->authorize($held['ownerType'], Operation::Update, $held['owner'], $serverName);

                    continue;
                } catch (AuthorizationException) {
                    // fall through to the row's own update ability
                }
            }

            $this->authorizer->authorize($held['memberType'], Operation::Update, $held['member'], $serverName);
        }
    }

    /**
     * Collects the writable relationships named in a whole-resource write body's
     * `data.relationships`, resolved to their declared relation + parsed linkage. A
     * relationship that is unknown, or read-only for this operation, is skipped — the
     * read-only gate is request-aware and mirrors core's own
     * `AbstractResource::hydrateRelationships()`, which never sees these because the
     * handler strips relationships from the body before core hydrates.
     *
     * @return list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}>
     */
    private function extractRelationships(Server $server, string $type, JsonApiRequestInterface $body, bool $creating): array
    {
        $collected = [];
        foreach ($this->bodyRelationshipNames($body) as $name) {
            $relation = $this->resolveRelation($server, $type, $name);
            if ($relation === null || $relation->isReadOnlyFor($creating, $body)) {
                continue;
            }

            if ($relation->isToMany()) {
                if ($body->hasToManyRelationship($name)) {
                    $collected[] = ['relation' => $relation, 'linkage' => $body->getToManyRelationship($name)];
                }

                continue;
            }

            if ($body->hasToOneRelationship($name)) {
                $collected[] = ['relation' => $relation, 'linkage' => $body->getToOneRelationship($name)];
            }
        }

        return $collected;
    }

    /**
     * Applies each collected relationship to the entity through the persister's
     * relationship seam in {@see Mode::Replace}, or through the relation's own fill hook when
     * it declares one, and returns the entity. An embedded relationship is a FULL
     * replacement of the named association, so on an UPDATE each one is gated by
     * {@see guardMutability()} in Mode::Replace (the same gate the `/relationships/{rel}`
     * endpoint applies, so a `cannotReplace(fn)` relation embedded in a PATCH raises the
     * same typed `403`); the gate is SKIPPED on a create (a create sets the initial state,
     * there is nothing to replace).
     *
     * @param list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}> $relationships
     */
    private function applyRelationships(
        DataPersisterInterface $persister,
        string $type,
        object $entity,
        array $relationships,
        JsonApiRequestInterface $body,
        bool $creating,
        Server $server,
        JsonApiRequestInterface $request,
        bool $flush,
    ): object {
        foreach ($relationships as $relationship) {
            $relation = $relationship['relation'];
            if ($creating === false) {
                $this->guardMutability($relation, $relation->name(), $relationship['linkage'], Mode::Replace, $body, $entity);
                $this->guardMembership($relation, $relationship['linkage'], $body, $entity, $server, $request);
            }

            $linkage = $this->scopeWrittenLinkage($entity, $relation, $relationship['linkage'], Mode::Replace, $server, $request, $creating);
            $this->guardReparenting($type, $entity, $relation, $linkage, Mode::Replace, $this->serverNameFor($request, $server));
            if ($this->declaresLinkRows($relation)) {
                $entity = $this->writeDeclaredLinkRows($server, $persister, $type, $entity, $relation, $linkage, Mode::Replace, $request, $flush);

                continue;
            }

            if (RelationFillHook::declaredBy($relation)) {
                // The create or update that follows persists what the hook wrote.
                $this->queueLinkRowWrite($server, $entity, $relation, $linkage, Mode::Replace, $request);
                $entity = RelationFillHook::apply($relation, $entity, $linkage, Mode::Replace);

                continue;
            }

            $persister->mutateRelationship($type, $entity, $relation, $linkage, Mode::Replace, flush: $flush);
        }

        return $entity;
    }

    /**
     * The linkage a persister applies for a relationship write, scoped by each related type's
     * own provider ({@see ScopedRelationshipWrites}): an id that provider does not serve is a
     * `404` like an absent one, and a to-many replace on an existing parent keeps the members
     * the client cannot see.
     */
    private function scopeWrittenLinkage(
        object $parent,
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        Server $server,
        JsonApiRequestInterface $request,
        bool $creating = false,
    ): ToOneRelationship|ToManyRelationship {
        $this->scopedWrites ??= new ScopedRelationshipWrites($this->providers, $this->relationshipLinkage->members());
        $this->scopedWrites->assertVisible($relation, $linkage);

        if ($creating || $mode !== Mode::Replace || !$linkage instanceof ToManyRelationship) {
            return $linkage;
        }

        return $this->scopedWrites->withHiddenMembers($parent, $relation, $linkage, $server, $request);
    }

    /**
     * Splits collected relationships into `[beforeCreate, deferred]` by STORAGE SIDE, not
     * cardinality. Only an **owner-side FK** relation — a {@see BelongsTo}
     * (but NOT its {@see HasOne} subclass) or a {@see MorphTo} — carries its foreign key on the
     * parent row, so it can be applied before the create and committed by the create's own
     * insert. Every OTHER relation is an inverse FK on the related rows (a {@see HasOne}/
     * {@see HasMany}) or a join table ({@see BelongsToMany}/{@see MorphToMany}) whose write
     * needs the parent's primary key, so it is DEFERRED to after `create()` returns the keyed
     * parent. Partitioning a `HasOne` (an inverse-FK to-one) by cardinality would land it in
     * the pre-create bucket and save the related row with a NULL foreign key on the not-yet-keyed
     * parent — silently dropping the association (or 500ing a NOT-NULL FK); the storage-side
     * split closes that.
     *
     * @param list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}> $relationships
     *
     * @return array{0: list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}>, 1: list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}>}
     */
    private function partitionForCreateOrder(array $relationships): array
    {
        $beforeCreate = [];
        $deferred = [];
        foreach ($relationships as $relationship) {
            if ($this->appliesBeforeCreate($relationship['relation'])) {
                $beforeCreate[] = $relationship;

                continue;
            }

            $deferred[] = $relationship;
        }

        return [$beforeCreate, $deferred];
    }

    /**
     * Whether a relation stores its foreign key on the PARENT row (an owner-side to-one), so
     * it may be applied inline before `create()`. True only for a {@see BelongsTo} that is not
     * a {@see HasOne} (core's {@see HasOne} extends {@see BelongsTo}, but is an INVERSE FK on
     * the related row) or a {@see MorphTo} (an owner-side polymorphic FK). Everything else —
     * inverse-FK to-ones/to-manys and join tables — must be deferred to after the parent is
     * keyed.
     */
    private function appliesBeforeCreate(RelationInterface $relation): bool
    {
        // A fill hook writes onto the entity like an attribute, so the create's insert saves it.
        return RelationFillHook::declaredBy($relation)
            || ($relation instanceof BelongsTo && !$relation instanceof HasOne)
            || $relation instanceof MorphTo;
    }

    /**
     * The relationship names present in the write body's `data.relationships` member, or
     * an empty list when the document carries none.
     *
     * @return list<string>
     */
    private function bodyRelationshipNames(JsonApiRequestInterface $body): array
    {
        $data = $body->getResource();
        if (!\is_array($data)) {
            return [];
        }

        $relationships = $data['relationships'] ?? null;
        if (!\is_array($relationships)) {
            return [];
        }

        /** @var list<string> $names */
        $names = \array_values(\array_filter(\array_keys($relationships), '\is_string'));

        return $names;
    }

    /**
     * A copy of the write body with `data.relationships` removed, so core's hydrator
     * hydrates only the id + attributes and never assigns a scalar linkage id to a typed
     * association property — the associations apply through the persister seam instead.
     */
    /**
     * The body with each `DateTime` attribute bound for a datetime-cast column of an
     * Eloquent model moved into the zone the column is stored in. Eloquent formats a
     * date-time as `Y-m-d H:i:s` the moment it is assigned, dropping the offset, so a
     * value sent in `Z` has to arrive in that zone already or the column stores the wrong
     * local time. The instant is unchanged, and a value that does not parse is left for
     * core's cast to reject.
     */
    private function datesInStorageZone(?AbstractResource $resource, object $entity, JsonApiRequestInterface $body): JsonApiRequestInterface
    {
        if ($resource === null || !$entity instanceof Model) {
            return $body;
        }

        /** @var array<string, mixed> $document */
        $document = (array) $body->getParsedBody();
        $attributes = \is_array($document['data'] ?? null) ? ($document['data']['attributes'] ?? null) : null;
        if (!\is_array($attributes)) {
            return $body;
        }

        $dates = new NaiveDateTimeStorage();
        $changed = false;
        foreach ($resource->allFields() as $field) {
            if (!$field instanceof DateTime || $field instanceof Date || $field instanceof Time) {
                continue;
            }

            $value = DateTimeParser::tryParse($attributes[$field->name()] ?? null);
            if ($value === null || !$dates->isNaive($entity, $field->column() ?? $field->name())) {
                continue;
            }

            $attributes[$field->name()] = $dates->toStorage($value)->format('Y-m-d\\TH:i:s.uP');
            $changed = true;
        }

        if (!$changed) {
            return $body;
        }

        $document['data']['attributes'] = $attributes;
        $converted = $body->withParsedBody($document);
        \assert($converted instanceof JsonApiRequestInterface);

        return $converted;
    }

    /**
     * Rejects a resource object whose `attributes`, `relationships` or `meta`, or one of
     * whose relationships, is present but not an object. The write path reads each of
     * them as a map and would otherwise skip one it can't read.
     *
     * @throws RequestBodyInvalidJsonApi
     */
    private function assertObjectMembers(JsonApiRequestInterface $body): void
    {
        $data = $body->getResource();
        if (!\is_array($data)) {
            return;
        }

        $violations = [];
        foreach (['attributes', 'relationships', 'meta'] as $member) {
            if (\array_key_exists($member, $data) && !\is_array($data[$member])) {
                $violations[] = ['message' => \sprintf('the "%s" member must be an object', $member), 'property' => '/data/' . $member];
            }
        }

        foreach (\is_array($data['relationships'] ?? null) ? $data['relationships'] : [] as $name => $relationship) {
            if (!\is_array($relationship)) {
                $violations[] = ['message' => \sprintf('the "%s" relationship must be an object', $name), 'property' => '/data/relationships/' . \str_replace(['~', '/'], ['~0', '~1'], (string) $name)];
            }
        }

        if ($violations !== []) {
            throw new RequestBodyInvalidJsonApi($violations);
        }
    }

    /**
     * Sets each top-level {@see Union} attribute the write sent on an Eloquent model to the
     * union's stored form, so its column holds JSON: a date member's string rather than the
     * `\DateTimeImmutable` it hydrated. A union inside an `Obj` or a list is stored as a
     * string by core already, and a union a write hook fills is the hook's to store.
     */
    private function unionsInStoredForm(?AbstractResource $resource, object $entity, JsonApiRequestInterface $body, bool $creating): void
    {
        if ($resource === null || !$entity instanceof Model) {
            return;
        }

        $data = $body->getResource();
        $attributes = \is_array($data) ? ($data['attributes'] ?? null) : null;
        if (!\is_array($attributes)) {
            return;
        }

        foreach ($resource->allFields() as $field) {
            $column = $field instanceof Union ? $field->column() : null;
            if ($column === null || !$field instanceof Union || $field->relatedVia() !== null || $field->hydratesThroughHook()
                || !\array_key_exists($field->name(), $attributes) || $field->isReadOnlyFor($creating, $body)) {
                continue;
            }

            try {
                $entity->setAttribute($column, $field->toStoredForm($field->castWireValue($attributes[$field->name()])));
            } catch (AttributeValueInvalid) {
                // hydration refused it already
            }
        }
    }

    private function withoutRelationships(JsonApiRequestInterface $body): JsonApiRequestInterface
    {
        /** @var array<string, mixed> $document */
        $document = (array) $body->getParsedBody();
        $data = $document['data'] ?? null;
        if (!\is_array($data) || !isset($data['relationships'])) {
            return $body;
        }

        unset($data['relationships']);
        $document['data'] = $data;

        $stripped = $body->withParsedBody($document);
        \assert($stripped instanceof JsonApiRequestInterface);

        return $stripped;
    }

    /**
     * Whether `$relation` is a `MorphToMany` declared with `identifiedByRows()`, whose link
     * rows Laravel writes from core's plan.
     *
     * @phpstan-assert-if-true MorphToMany $relation
     */
    private function declaresLinkRows(RelationInterface $relation): bool
    {
        return $relation instanceof MorphToMany && $relation->linkRowFields() !== null;
    }

    /**
     * Writes `$linkage` to a declared `MorphToMany`'s link rows: core plans the deletes, the
     * temporary and final positions, the pivot values and the inserts from the members the
     * parent links now and those a scope hides from the caller, which a write keeps, and the
     * persister applies them in order.
     */
    private function writeDeclaredLinkRows(Server $server, DataPersisterInterface $persister, string $type, object $parent, MorphToMany $relation, ToOneRelationship|ToManyRelationship $linkage, Mode $mode, JsonApiRequestInterface $request, bool $flush): object
    {
        if (!$persister instanceof WritesLinkRowsInterface) {
            throw new \LogicException(\sprintf(
                'MorphToMany relation "%s" on type "%s" is declared with identifiedByRows(), so its persister must implement %s to write its link rows.',
                $relation->name(),
                $type,
                WritesLinkRowsInterface::class,
            ));
        }
        if (!$linkage instanceof ToManyRelationship) {
            return $parent;
        }

        $links = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            if ($identifier->id !== null) {
                $pivot = $identifier->meta['pivot'] ?? [];
                $links[] = [$identifier->type, $this->relationshipLinkage->members()->storageKey($server, $identifier->type, $identifier->id), \is_array($pivot) ? $pivot : []];
            }
        }

        if ($parent instanceof Model) {
            $parent->unsetRelation($relation->column() ?? $relation->name());
        }
        $members = $this->relationshipLinkage->members();
        $plan = LinkRowWritePlan::for(
            $relation,
            $parent,
            $mode,
            $links,
            $relation->identifiedMembers($parent, $request),
            $members->hiddenMembers($server, $parent, $relation, $this->linkRows($type), $request),
        );

        return $persister->writeLinkRows($type, $parent, $relation, $plan, $flush);
    }

    /**
     * The stored pivot values of an identified `MorphToMany`'s link rows on `$parent`, keyed by
     * member type and wire id, for the merge-before-validate pass.
     *
     * @return array<string, array<string, mixed>>
     */
    private function identifiedPivots(Server $server, object $parent, MorphToMany $relation, JsonApiRequestInterface $request): array
    {
        $pivots = [];
        foreach ($this->relationshipLinkage->members()->membersByKey($server, $parent, $relation, $request) as $key => $member) {
            foreach ($relation->storedPivotFields() as $field) {
                $pivots[$key][$field->name()] = $member->row === null ? null : Accessor::get($member->row, $field->column() ?? $field->name());
            }
        }

        return $pivots;
    }

    /**
     * Writes the positions of an identified `MorphToMany` positioned with `positionedBy()` onto
     * its link rows once `fillUsing()` has written them: a replace gives each member its linkage
     * index, an add appends the new members after the highest position, and a remove leaves
     * gaps. Core's {@see Positions::plan()} orders the writes so a unique owner and position
     * index never sees two rows at one position: every row that moves is parked past the
     * positions in use, then each takes its final position. A row the hook creates should leave
     * its position empty for this to fill.
     *
     * @param array<string, int>              $held    the positions before the write, by type and wire id
     * @param array<string, IdentifiedMember> $members the members after it, by type and wire id
     */
    private function writeLinkRowPositions(MorphToMany $relation, ToManyRelationship $linkage, Mode $mode, array $held, array $members): void
    {
        $position = PositionColumn::of($relation);
        if ($position === null) {
            return;
        }

        $sent = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            $sent[] = $identifier->type . "\0" . $identifier->id;
        }

        $current = \array_intersect_key($held, $members);
        $target = $mode === Mode::Replace
            ? Positions::forReplace([...$sent, ...\array_keys(\array_diff_key($members, \array_flip($sent)))])
            : $current + Positions::forAdd($current, $sent);
        $plan = Positions::plan($current, \array_intersect_key($target, $members));

        foreach ([$plan->temporary, $plan->final] as $pass) {
            foreach ($pass as $key => $at) {
                $row = $members[$key]->row;
                if (\is_object($row)) {
                    Accessor::set($row, $position, $at);
                    if ($row instanceof Model && $row->exists) {
                        $row->save();
                    }
                }
            }
        }
    }

    /**
     * Notes the members an identified `MorphToMany` with stored pivot fields holds before a write
     * of `$linkage`, so {@see writeLinkRows()} can tell a new member from an existing one once
     * the relation's `fillUsing()` has written the link rows.
     */
    private function queueLinkRowWrite(Server $server, object $parent, RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, Mode $mode, JsonApiRequestInterface $request): void
    {
        if ($mode === Mode::Remove || !IdentifiedMorphMembers::identifies($relation) || !$linkage instanceof ToManyRelationship
            || $relation->storedPivotFields() === [] || $relation->linkRowFields() !== null) {
            return;
        }

        $position = PositionColumn::of($relation);
        $held = [];
        foreach ($this->relationshipLinkage->members()->membersByKey($server, $parent, $relation, $request) as $key => $member) {
            $at = $position !== null && $member->row !== null ? Accessor::get($member->row, $position) : null;
            $held[$key] = \is_numeric($at) ? (int) $at : 0;
        }
        $this->linkRowWrites[] = [$relation, $linkage, $mode, $held];
    }

    /**
     * Writes the writable pivot fields each queued linkage member sent in `meta.pivot` onto its
     * link row, after `fillUsing()` has written the rows: a member new to the relation takes its
     * create-context fields, an existing one its update-context fields. An Eloquent row is saved,
     * and any other object row is written in place, which is how the in-memory witness keeps it.
     */
    private function writeLinkRows(Server $server, object $parent, JsonApiRequestInterface $request): void
    {
        $writes = $this->linkRowWrites;
        $this->linkRowWrites = [];

        foreach ($writes as [$relation, $linkage, $mode, $held]) {
            if ($parent instanceof Model) {
                $parent->unsetRelation($relation->column() ?? $relation->name());
            }
            $members = $this->relationshipLinkage->members()->membersByKey($server, $parent, $relation, $request);
            $this->writeLinkRowPositions($relation, $linkage, $mode, $held, $members);

            foreach ($linkage->resourceIdentifiers as $identifier) {
                $pivot = $identifier->meta['pivot'] ?? null;
                $row = $members[$identifier->type . "\0" . $identifier->id]->row ?? null;
                if (!\is_array($pivot) || !\is_object($row)) {
                    continue;
                }

                $creating = !isset($held[$identifier->type . "\0" . $identifier->id]);
                foreach ($relation->writablePivotFields($creating) as $field) {
                    if (\array_key_exists($field->name(), $pivot)) {
                        Accessor::set($row, $field->column() ?? $field->name(), $pivot[$field->name()]);
                    }
                }

                // An unsaved row is an in-memory one, held on the parent.
                if ($row instanceof Model && $row->exists && $row->isDirty()) {
                    $row->save();
                }
            }
        }
    }

    /**
     * The existing pivot rows of each `belongsToMany` pivot relation carried in the write
     * body, keyed by relation name then related id — read off the loaded parent through
     * {@see DataProviderInterface::fetchRelationshipPivot()}. The validator folds these
     * under the incoming linkage meta per member for the merge-before-validate pass (an
     * existing member validates its merged pivot in the update context, a new member its
     * incoming meta in create context). A non-pivot relation, or a provider storing no
     * pivot, contributes nothing.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function existingPivots(Server $server, string $type, JsonApiRequestInterface $body, object $parent): array
    {
        $provider = $this->providers->forType($type);

        $existingPivots = [];
        foreach ($this->bodyRelationshipNames($body) as $name) {
            $relation = $this->resolveRelation($server, $type, $name);
            if ($relation !== null && IdentifiedMorphMembers::identifies($relation) && $relation->pivotFields() !== []) {
                $existingPivots[$name] = $this->identifiedPivots($server, $parent, $relation, $body);

                continue;
            }
            if (!$relation instanceof BelongsToMany || $relation->pivotFields() === []) {
                continue;
            }

            $pivot = $provider->fetchRelationshipPivot($type, $parent, $relation);
            if ($pivot !== []) {
                $existingPivots[$name] = $pivot;
            }
        }

        return $existingPivots;
    }

    /**
     * Runs `$work` inside the persister's transaction when it is
     * {@see TransactionalDataPersisterInterface} — a rollback boundary around the mutating
     * write tail (hydrate → validateEntity → commit) so a post-hydration failure discards
     * the partial write rather than leaking it. A persister that cannot transact runs
     * `$work` directly (it owns its own atomicity). A throwable rolls the transaction back
     * and re-propagates unchanged (rendered by the exception renderer).
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function writeTransactionally(DataPersisterInterface $persister, \Closure $work): mixed
    {
        if (!$persister instanceof TransactionalDataPersisterInterface) {
            return $work();
        }

        $persister->beginTransaction();

        try {
            $result = $work();
        } catch (\Throwable $throwable) {
            $persister->rollback();

            throw $throwable;
        }

        $persister->commit();

        return $result;
    }

    /**
     * The originating JSON:API request from the operation context, asserted non-null: a
     * write operation is always dispatched from an HTTP request through the controller,
     * so the context always carries one.
     */
    private function jsonApiRequest(OperationContext $context): JsonApiRequestInterface
    {
        $request = $context->httpRequest();
        \assert($request instanceof JsonApiRequestInterface);

        return $request;
    }

    /**
     * Whether the client applied a filter the resource declares
     * {@see SupportsSingular singular} — the trigger to collapse the collection to a
     * zero-to-one ({@see DataResponse::fromResource()}) response. A requested key resolves
     * through {@see FilterMatcher}, so a filter family's key is singular when the filter it
     * resolves to is.
     *
     * @param list<FilterInterface> $filters
     */
    private function appliesSingularFilter(array $filters, QueryParameters $queryParameters, ?JsonApiRequestInterface $request): bool
    {
        foreach (\array_keys($queryParameters->filter) as $key) {
            $filter = FilterMatcher::match($filters, (string) $key, $request);
            if ($filter instanceof SupportsSingular && $filter->isSingular()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Materializes the result's items once, as a list, so they can be both peeked
     * (the singular first match) and rendered without consuming a one-shot iterator.
     *
     * @param CollectionResult<object> $result
     *
     * @return list<object>
     */
    private function materialize(CollectionResult $result): array
    {
        return \is_array($result->items)
            ? \array_values($result->items)
            : \iterator_to_array($result->items, false);
    }

    /**
     * Dispatches a lifecycle event through the injected Laravel {@see Dispatcher} — a
     * no-op when no dispatcher is wired (a stripped-down programmatic wiring). A
     * before-event listener that throws a {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}
     * propagates out of here to the route-scoped exception renderer; an after-event
     * listener's replaced response is read back off the event by the caller.
     */
    private function dispatch(object $event): void
    {
        $this->dispatcher?->dispatch($event);
    }

    /**
     * Fires the after-fetch-collection lifecycle event, letting a listener/hook
     * replace the response. Returns the handler's response unchanged for a
     * programmatic dispatch with no request to build the event from.
     *
     * @param list<object> $items the materialized collection
     */
    /**
     * @param list<object>                                          $items
     * @param PaginatorInterface|null                               $paginator         the request-resolved paginator that paged the collection, null when none ran
     * @param list<\haddowg\JsonApi\Schema\Profile\ProfileInterface> $activatedProfiles profiles a cursor-paginated included relation activated, surfaced onto the document's applied set
     */
    private function afterFetchCollection(DataResponse $response, string $type, ?JsonApiRequestInterface $request, array $items, ?PaginatorInterface $paginator, array $activatedProfiles = []): DataResponse
    {
        if ($request !== null) {
            $event = new AfterFetchCollectionEvent($type, $request, $items, $this->serverName($request), $paginator);
            $this->dispatch($event);
            $response = $event->response() ?? $response;
        }

        return $activatedProfiles === [] ? $response : $response->withActivatedProfiles(...$activatedProfiles);
    }

    /**
     * Fires an After* lifecycle dispatch either inline (the single-op path) or
     * deferred to post-commit (an active Atomic Operations batch).
     *
     * On the single-op path the {@see WriteTransactionContext} is inactive (or
     * absent), so this runs `$fire` immediately and honours its response replacement.
     * Under an active batch (opened by the {@see \haddowg\JsonApiLaravel\Atomic\AtomicLoopBackend})
     * the dispatch is enqueued to run after the batch's transaction commits, and the
     * pre-After* `$response` is returned unchanged: the After* response replacement is
     * intentionally inert under atomic, because the aggregate batch result is
     * authoritative. A rolled-back batch never drains the queue, so its hooks never
     * fire.
     *
     * @template TResponse of object
     *
     * @param callable(): TResponse $fire     runs the After* dispatch and returns the (possibly replaced) response
     * @param TResponse             $response  the pre-After* response, returned verbatim when deferred
     *
     * @return TResponse
     */
    private function deferOrFire(callable $fire, object $response): object
    {
        if ($this->txContext !== null && $this->txContext->isActive()) {
            $this->txContext->enqueuePostCommit(static function () use ($fire): void {
                $fire();
            });

            return $response;
        }

        return $fire();
    }

    /**
     * The name of the server whose declarations authorize the operation: the request's
     * `_jsonapi_server` attribute, else the server the operation carries, else `default`.
     */
    private function serverNameOf(OperationContext $context): string
    {
        $request = $context->httpRequest();

        return $this->serverNameFor($request instanceof JsonApiRequestInterface ? $request : null, $context->server);
    }

    private function serverNameFor(?JsonApiRequestInterface $request, mixed $server): string
    {
        return ($this->serverNames ?? new ServerNames())->resolve($request, $server);
    }

    /**
     * The name of the server the request dispatched on, read from the `_jsonapi_server`
     * request attribute the {@see \haddowg\JsonApiLaravel\Http\JsonApiController}
     * stamps, defaulting to the implicit `default` server. Passed on each event so a
     * listener can resolve the right server in a multi-server app.
     */
    private function serverName(JsonApiRequestInterface $request): string
    {
        $name = $request->getAttribute(TargetResolver::SERVER_ATTRIBUTE);

        return \is_string($name) && $name !== '' ? $name : ServerRegistry::DEFAULT_SERVER;
    }
}
