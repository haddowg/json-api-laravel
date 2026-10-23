<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationshipLinkage;
use haddowg\JsonApi\Server\Server;

/**
 * Decides the target a to-one relation renders when its related type is served by its own
 * scoping provider (see {@see DataProviderRegistry::scopingProviderFor()}). The target is read
 * off the parent as usual, then shown only if that provider's {@see DataProviderInterface::fetchOne()}
 * still returns it for this request; otherwise the relation renders as none. The parent's own
 * object is what renders, so a write through an `on()` hop lands on the object the parent holds.
 *
 * Core asks for it through the to-one linkage seam on every to-one read (linkage, `included`,
 * the relationship endpoint, `on()` hops on read and write, `MorphTo`), and the handler asks
 * it for the to-one related endpoint. Answers are memoized per parent object and relation until
 * {@see forget()}, so the include preload walks the very objects the render shows.
 */
final class ScopedToOneTargets
{
    private ?Server $server = null;

    /**
     * @var \WeakMap<object, array<string, ToOneRelationshipLinkage|null>>
     */
    private \WeakMap $memo;

    public function __construct(private readonly DataProviderRegistry $providers)
    {
        $this->memo = new \WeakMap();
    }

    /**
     * Sets the server whose serializers identify targets for the operation being handled, and
     * drops every memoized answer.
     */
    public function bind(?Server $server): void
    {
        $this->server = $server;
        $this->forget();
    }

    /**
     * Drops every memoized answer, so a target changed by a write is decided again.
     */
    public function forget(): void
    {
        $this->memo = new \WeakMap();
    }

    /**
     * The target to render for to-one `$relation` on `$model`, `none()` when its provider hides
     * it, or `null` when no related type of the relation has its own scoping provider.
     */
    public function linkageFor(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): ?ToOneRelationshipLinkage
    {
        if ($relation->isToMany() || !\is_object($model) || !$this->relatesToScopingProvider($relation)) {
            return null;
        }

        $name = $relation->name();
        $entry = $this->memo[$model] ?? [];
        if (!\array_key_exists($name, $entry)) {
            $entry[$name] = $this->resolve($model, $relation, $request);
            $this->memo[$model] = $entry;
        }

        return $entry[$name];
    }

    /**
     * The target `$relation` renders on `$model`: the scoped answer when there is one,
     * otherwise the relation's own read.
     */
    public function targetFor(object $model, RelationInterface $relation, JsonApiRequestInterface $request): mixed
    {
        $linkage = $this->linkageFor($model, $relation, $request);

        return $linkage !== null ? $linkage->target : $relation->readValue($model, $request);
    }

    private function resolve(object $model, RelationInterface $relation, JsonApiRequestInterface $request): ?ToOneRelationshipLinkage
    {
        $target = $relation->readValue($model, $request);
        if (!\is_object($target)) {
            return ToOneRelationshipLinkage::none();
        }

        // Without a server the target cannot be identified, so it is withheld.
        $serializer = $this->server === null ? null : $relation->resolveSerializer($target, $this->server);
        if ($serializer === null) {
            return ToOneRelationshipLinkage::none();
        }

        $type = $serializer->getType($target);
        $provider = $this->providers->scopingProviderFor($type);
        if ($provider === null) {
            return null;
        }

        return $provider->fetchOne($type, $serializer->getId($target)) === null
            ? ToOneRelationshipLinkage::none()
            : ToOneRelationshipLinkage::to($target);
    }

    private function relatesToScopingProvider(RelationInterface $relation): bool
    {
        foreach ($relation->relatedTypes() as $type) {
            if ($this->providers->scopingProviderFor($type) !== null) {
                return true;
            }
        }

        return false;
    }
}
