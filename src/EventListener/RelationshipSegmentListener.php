<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\EventListener;

use haddowg\JsonApiLaravel\Operation\TargetResolver;
use haddowg\JsonApiLaravel\Routing\JsonApiRoute;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use haddowg\JsonApiLaravel\Server\TypeMetadataResolver;
use Illuminate\Routing\Events\RouteMatched;

/**
 * Rewrites the `{relationship}` parameter of a matched related or relationship route
 * from the URL segment to the relation's name, as soon as the router matches it, so
 * every later reader ({@see TargetResolver}, {@see JsonApiRoute::of()}, route middleware,
 * the controller) sees the name a relation is declared under.
 *
 * The segment is the relation's URI field name, or its name for a client still on the
 * old URL (ADR 0035). A segment no relation answers to is left as it is, and the handler
 * answers `404` for it as before.
 */
final class RelationshipSegmentListener
{
    public function __construct(
        private readonly ServerRegistry $servers,
        private readonly TypeMetadataResolver $types,
    ) {}

    public function handle(RouteMatched $event): void
    {
        $route = $event->route;
        if (($route->defaults[JsonApiRoute::MARKER] ?? false) !== true) {
            return;
        }

        $type = $route->defaults[TargetResolver::TYPE_ATTRIBUTE] ?? null;
        if (!\is_string($type) || $type === '' || !$route->hasParameter(TargetResolver::RELATIONSHIP_ATTRIBUTE)) {
            return;
        }

        $segment = $route->parameter(TargetResolver::RELATIONSHIP_ATTRIBUTE);
        if (!\is_string($segment) || $segment === '') {
            return;
        }

        $server = $route->defaults[TargetResolver::SERVER_ATTRIBUTE] ?? null;
        $server = $this->servers->get(\is_string($server) && $server !== '' ? $server : null);

        $relation = $this->types->relationAtUriSegment($server, $type, $segment);
        if ($relation !== null && $relation->name() !== $segment) {
            $route->setParameter(TargetResolver::RELATIONSHIP_ATTRIBUTE, $relation->name());
        }
    }
}
