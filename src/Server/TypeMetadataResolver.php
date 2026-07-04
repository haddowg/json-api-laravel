<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;

/**
 * Resolves a registered type's declarative relation metadata (its resource, its
 * declared relations) off a {@see Server} — the thin Laravel twin of the Symfony
 * bundle's `TypeMetadataResolver` (bundle ADR 0021), the single seam the relationship
 * batchers and the operation handler's related/relationship arms read relation metadata
 * through.
 *
 * It tolerates a type registered as a **bare serializer/hydrator pair** (no
 * {@see AbstractResource}, so no field inventory): such a type has no resource, no
 * relations and no filter/sort vocabulary, and every lookup returns the absent value
 * ({@see resourceFor()} `null`, {@see relationsFor()} `[]`) rather than throwing — so the
 * relationship machinery stays generic over both a full resource and a bare pair without
 * per-call-site branching.
 *
 * The {@see Server} is passed per call (it flows from the operation context, and the
 * architecture is multi-server-capable) rather than held. The standalone
 * `#[AsJsonApiRelations]` registry the bundle folds in is a later-phase capstone feature
 * (PLAN decision 3) not yet present here, so relations resolve resource-only.
 */
final class TypeMetadataResolver
{
    /**
     * The resource registered for `$type`, or `null` when the type is a bare
     * serializer/hydrator pair (no field inventory). Never throws.
     */
    public function resourceFor(Server $server, string $type): ?AbstractResource
    {
        return $server->hasResourceFor($type) ? $server->resourceFor($type) : null;
    }

    /**
     * The declared, non-hidden relation named `$name` on `$type`, or `null` when the
     * type is a bare pair or declares no such relation — the handler maps `null` to a
     * JSON:API `404`.
     */
    public function relationNamed(Server $server, string $type, string $name): ?RelationInterface
    {
        return $this->resourceFor($server, $type)?->relationNamed($name);
    }

    /**
     * The declared relation named `$name` on `$type` **including hidden relations** —
     * the hidden-inclusive twin of {@see relationNamed()}. `null` when neither a
     * non-hidden nor a hidden relation of that name is declared.
     */
    public function relationNamedIncludingHidden(Server $server, string $type, string $name): ?RelationInterface
    {
        return $this->resourceFor($server, $type)?->relationNamedIncludingHidden($name);
    }

    /**
     * Every declared, non-hidden relation on `$type` — the single enumeration the
     * include preloader and the count batcher walk to decide what to batch. An empty
     * list for a bare serializer/hydrator pair (no field inventory).
     *
     * @return list<RelationInterface>
     */
    public function relationsFor(Server $server, string $type): array
    {
        $resource = $this->resourceFor($server, $type);
        if ($resource === null) {
            return [];
        }

        $relations = [];
        foreach ($resource->fields() as $field) {
            if ($field instanceof RelationInterface && !$field->isHidden()) {
                $relations[] = $field;
            }
        }

        return $relations;
    }
}
