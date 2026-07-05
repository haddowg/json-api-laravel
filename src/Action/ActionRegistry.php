<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApiLaravel\OpenApi\Metadata\ActionMetadata;
use haddowg\JsonApiLaravel\OpenApi\Metadata\ActionMetadataProviderInterface;
use haddowg\JsonApiLaravel\OpenApi\Metadata\TagNameResolver;

/**
 * Resolves an {@see ActionDescriptor} and its {@see ActionHandlerInterface} by the
 * composite key `(server, type, scope, path)` — the Laravel twin of the bundle's
 * `Action\ActionRegistry`, and the {@see ActionMetadataProviderInterface} the OpenAPI
 * {@see \haddowg\JsonApiLaravel\OpenApi\Metadata\MetadataSource} reads a type's actions
 * through (the "A stubs `actions()` first, B fills it" handoff).
 *
 * The descriptors arrive resolved from the (cacheable) discovery snapshot; the handler is
 * constructed lazily through the container so a handler with real constructor
 * dependencies is built only when its action is actually invoked.
 */
final class ActionRegistry implements ActionMetadataProviderInterface
{
    /**
     * @var list<ActionDescriptor>
     */
    private readonly array $descriptors;

    /**
     * @var array<string, ActionDescriptor>
     */
    private array $byKey = [];

    /**
     * @param list<ActionDescriptor>                    $descriptors    the resolved action descriptors, in discovery order
     * @param \Closure(class-string<ActionHandlerInterface>): ActionHandlerInterface $handlerResolver constructs a handler by its class-string
     * @param array<string, list<string>>              $typeTags       the explicit OpenAPI tags each mount type declares, keyed by JSON:API type (an action with no tags inherits these before the humanized default)
     */
    public function __construct(
        array $descriptors,
        private readonly \Closure $handlerResolver,
        private readonly TagNameResolver $tagNames,
        private readonly array $typeTags = [],
    ) {
        $this->descriptors = \array_values($descriptors);
        foreach ($this->descriptors as $descriptor) {
            $this->byKey[self::key($descriptor->server, $descriptor->type, $descriptor->scope, $descriptor->path)] = $descriptor;
        }
    }

    /**
     * The descriptor registered for the composite key, or `null` when no action is
     * declared for that `(server, type, scope, path)` — the {@see ActionInvoker} maps a
     * `null` to a `404`.
     */
    public function descriptorFor(string $server, string $type, ActionScope $scope, string $path): ?ActionDescriptor
    {
        return $this->byKey[self::key($server, $type, $scope, $path)] ?? null;
    }

    /**
     * The handler for a resolved descriptor, constructed lazily through the container.
     */
    public function handlerFor(ActionDescriptor $descriptor): ActionHandlerInterface
    {
        return ($this->handlerResolver)($descriptor->handlerClass);
    }

    /**
     * Every action declared on `$server`, in discovery order — the enumeration the route
     * registrar and the {@see \haddowg\JsonApiLaravel\Action\ActionLinkContributor} walk.
     *
     * @return list<ActionDescriptor>
     */
    public function forServer(string $server): array
    {
        return \array_values(\array_filter(
            $this->descriptors,
            static fn(ActionDescriptor $descriptor): bool => $descriptor->server === $server,
        ));
    }

    /**
     * The custom actions mounted on `$type` within `$server`, resolved to core's
     * {@see ActionMetadataInterface} (empty action tags re-resolved to the mount type's
     * explicit tags, then the humanized default), in discovery order — the
     * {@see ActionMetadataProviderInterface} seam.
     *
     * @return list<ActionMetadataInterface>
     */
    public function forServerType(string $server, string $type): array
    {
        $metadata = [];
        foreach ($this->descriptors as $descriptor) {
            if ($descriptor->server !== $server || $descriptor->type !== $type) {
                continue;
            }

            $metadata[] = new ActionMetadata($descriptor, $this->resolveActionTags($descriptor));
        }

        return $metadata;
    }

    /**
     * The OpenAPI tags for an action: its own explicit tags, else the mount type's explicit
     * `#[AsJsonApiResource(tags: …)]` tags, else the humanized-type default — the byte-compat
     * twin of the bundle's `withResolvedTags()`.
     *
     * @return list<string>
     */
    private function resolveActionTags(ActionDescriptor $descriptor): array
    {
        if ($descriptor->tags !== []) {
            return $descriptor->tags;
        }

        $typeTags = $this->typeTags[$descriptor->type] ?? [];

        return $typeTags !== [] ? $typeTags : [$this->tagNames->defaultFor($descriptor->type)];
    }

    /**
     * The composite map key for `(server, type, scope, path)`. The scope contributes its
     * case name; the segments are joined by a NUL that cannot appear in a server name, a
     * JSON:API type or a URL path segment, so the key is unambiguous.
     */
    public static function key(string $server, string $type, ActionScope $scope, string $path): string
    {
        return $server . "\0" . $type . "\0" . $scope->name . "\0" . $path;
    }
}
