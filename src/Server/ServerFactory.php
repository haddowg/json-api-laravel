<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApi\Server\Server;
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
 * This is the Phase 0 subset of the Symfony bundle's `ServerFactory`; the relationship
 * seams, profiles, and the serving/events bridge are threaded in later phases.
 */
final class ServerFactory
{
    private ?Server $server = null;

    /**
     * @param \Closure(class-string): object                     $resolver        the container resolver core builds registered resources through
     * @param list<class-string<AbstractResource>>               $resourceClasses this server's resource class-strings
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
            ->withRelationshipCount($this->relationshipCount)
            ->withRelationshipLoadState($this->relationshipLoadState)
            ->withContainer($this->resolver);

        foreach ($this->resourceClasses as $resourceClass) {
            $server = $server->register($resourceClass);
        }

        return $this->server = $server->withHandler($this->handler);
    }
}
