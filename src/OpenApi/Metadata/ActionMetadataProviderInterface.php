<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;

/**
 * The seam the {@see MetadataSource} reads a type's custom actions
 * (`#[AsJsonApiAction]`) through, so the OpenAPI subtree does not hard-depend on the
 * action subsystem (built alongside atomic operations in a sibling Phase-4 workflow).
 *
 * The action subsystem's registry implements this interface and is bound in the
 * container; when no implementation is bound the {@see MetadataSource} treats every
 * type as action-less ({@see \haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface::actions()}
 * returns `[]`), and core's projector emits no `-actions` paths. This is the "A stubs
 * `actions()` first, B fills it" handoff (Phase-4 build split): the metadata source is
 * complete without actions and picks them up transparently once the registry is bound.
 */
interface ActionMetadataProviderInterface
{
    /**
     * The custom actions mounted on `$type` within `$server`, already resolved to
     * core's {@see ActionMetadataInterface} (tags re-resolved against the mount type's
     * default), in declaration order. Empty when the type mounts no actions.
     *
     * @return list<ActionMetadataInterface>
     */
    public function forServerType(string $server, string $type): array;
}
