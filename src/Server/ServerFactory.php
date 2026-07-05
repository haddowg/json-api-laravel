<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApi\Serializer\RelationshipPaginationInterface;
use haddowg\JsonApi\Serializer\ResourceLinkContributorInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Event\ServingEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Builds the immutable core {@see Server} for one declared server from that server's
 * resource class-strings (the subset of the discovered resources assigned to it),
 * registered through core's lazy container resolver so a resource can have real
 * constructor dependencies; its base URI + JSON:API version; the PSR-17 factories; the
 * server-wide default paginator (the built-in {@see PagePaginator} capped at the
 * configured `max_per_page`, or none when the cap is 0); the include-depth cap and the
 * strict-query-parameter toggle; and the read operation handler.
 *
 * It deliberately does NOT install core's PSR-15 middleware chain — the Laravel
 * integration drives the request lifecycle from the invokable controller, which calls
 * `Server::dispatch()`. It calls `withHandler()` so `dispatch()` has a handler (core
 * throws without one). The built Server is an immutable value, so it is memoized and
 * shared.
 *
 * The relationship seams, profiles, and the serving/events bridge (a
 * `Server::withServing()` handler dispatching a {@see ServingEvent}, PLAN decision 10)
 * are all threaded here.
 */
final class ServerFactory
{
    private ?Server $server = null;

    /**
     * @param \Closure(class-string): object                                                        $resolver              the container resolver core builds registered resources through
     * @param list<class-string<AbstractResource>>                                                  $resourceClasses       this server's resource class-strings
     * @param array<string, class-string<\haddowg\JsonApi\Serializer\SerializerInterface>> $standaloneSerializers this server's standalone serializers, keyed by JSON:API type (no resource; PLAN decision 3, bundle ADR 0024)
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly OperationHandlerInterface $handler,
        private readonly \Closure $resolver,
        private readonly array $resourceClasses = [],
        private readonly string $baseUri = '',
        private readonly string $version = '1.1',
        private readonly int $maxPerPage = PagePaginator::DEFAULT_MAX_PER_PAGE,
        private readonly int $maxIncludeDepth = 0,
        private readonly bool $strictQueryParameters = true,
        // The per-request `?withCount` count seam holder, threaded into the memoized Server
        // once; the handler swaps its batched backing in on each read. Null leaves the count
        // seam unwired (core omits `meta.total`).
        private readonly ?RelationshipCountInterface $relationshipCount = null,
        // The storage-aware load-state predicate (the Eloquent reference wires one; the
        // in-memory witness leaves it null, so every relation is treated as loaded and
        // renders linkage data eagerly — the standalone default).
        private readonly ?RelationshipLoadStateInterface $relationshipLoadState = null,
        // The per-request Relationship Queries profile seam holders (the pagination + linkage
        // twins of the count holder), threaded into the memoized Server once; the handler swaps
        // each read's windowed backing in and clears them otherwise. Null leaves the profile
        // seams unwired (core emits no relationship pagination links and reads linkage off the
        // model — the profile-not-negotiated default).
        private readonly ?RelationshipPaginationInterface $relationshipPagination = null,
        private readonly ?RelationshipLinkageInterface $relationshipLinkage = null,
        // The out-of-band resource-link contributor (Phase 4): a per-server
        // ActionLinkContributor merges each `asLink` custom action's URL onto its mount
        // type's resources. Null leaves resource links exactly as the author's getLinks()
        // + the convention self link produce (the no-asLink-actions default).
        private readonly ?ResourceLinkContributorInterface $resourceLinkContributor = null,
        // The lifecycle-event dispatcher + this server's name, threaded so `create()`
        // installs a `Server::withServing()` handler that turns core's request-wide
        // `serving` seam into a package {@see ServingEvent} (PLAN decision 10). Null
        // dispatcher leaves the serving seam unwired.
        private readonly ?Dispatcher $dispatcher = null,
        private readonly string $serverName = 'default',
        // Standalone serializers (no resource), keyed by JSON:API type — registered
        // through core's registerSerializerHydrator() after the resources (PLAN decision
        // 3, the Laravel twin of bundle ADR 0024). Serialize-only unless the type's
        // operation allow-list opens read endpoints (charts/countries do).
        private readonly array $standaloneSerializers = [],
    ) {}

    /**
     * The configured, memoized Server for this server's API surface.
     */
    public function create(): Server
    {
        if ($this->server !== null) {
            return $this->server;
        }

        $server = Server::make()
            ->withBaseUri($this->baseUri)
            ->withVersion($this->version)
            ->withPsr17($this->responseFactory, $this->streamFactory)
            ->withDefaultPaginator($this->maxPerPage > 0 ? PagePaginator::make()->withMaxPerPage($this->maxPerPage) : null)
            ->withMaxIncludeDepth($this->maxIncludeDepth > 0 ? $this->maxIncludeDepth : null)
            ->withStrictQueryParameters($this->strictQueryParameters)
            // Register the Countable profile so `?withCount=<rel>` is recognized when the
            // client negotiates it (core gates `parseWithCount()` on the profile); the
            // relationship-object `meta.total` then reads the request-scoped count seam.
            ->withProfile(new CountableProfile())
            // Register the Relationship Queries profile so `relatedQuery[<path>][sort|filter]`
            // (and the `rQ` shorthand) is recognized when the client negotiates it; the windowed
            // per-relationship linkage/pagination then rides the two request-scoped holders.
            ->withProfile(new RelationshipQueriesProfile())
            ->withRelationshipCount($this->relationshipCount)
            ->withRelationshipLoadState($this->relationshipLoadState)
            ->withRelationshipPagination($this->relationshipPagination)
            ->withRelationshipLinkage($this->relationshipLinkage)
            ->withResourceLinkContributor($this->resourceLinkContributor)
            ->withContainer($this->resolver);

        foreach ($this->resourceClasses as $resourceClass) {
            $server = $server->register($resourceClass);
        }

        // Standalone serializer capabilities (PLAN decision 3, bundle ADR 0024): a type
        // registered with no resource. Core stores the pair; serializerFor() resolves the
        // service through the same container resolver, so the read pipeline renders it
        // exactly as a resource-backed type. Registered after the resources so a resource
        // for the same type always wins (a standalone registration is the resource-less
        // path). The hydrator arm is null here — the ported capability is serialize-only.
        foreach ($this->standaloneSerializers as $type => $serializerClass) {
            $server = $server->registerSerializerHydrator($type, $serializerClass, null);
        }

        // The serving bridge (PLAN decision 10): one core `serving` handler that turns
        // core's server-level seam (fired once per request inside `Server::dispatch()`,
        // core ADR 0050) into a package {@see ServingEvent}. A ServingEvent listener that
        // throws a JsonApiException aborts — the throw propagates out of the closure →
        // out of `dispatch()` → the route-scoped exception renderer. Wired only when a
        // dispatcher is present.
        if ($this->dispatcher !== null) {
            $dispatcher = $this->dispatcher;
            $serverName = $this->serverName;
            $server = $server->withServing(
                static function (JsonApiRequestInterface $request) use ($dispatcher, $serverName): void {
                    $dispatcher->dispatch(new ServingEvent($request, $serverName));
                },
            );
        }

        return $this->server = $server->withHandler($this->handler);
    }
}
