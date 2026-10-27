<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

use haddowg\JsonApi\OpenApi\Metadata\ActionScope as OpenApiActionScope;
use haddowg\JsonApi\OpenApi\OperationReference;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Link\ActionLink;
use haddowg\JsonApi\Schema\Link\OperationLink;
use haddowg\JsonApi\Serializer\ResourceLinkContributorInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Serializer\UriTypeAwareInterface;
use haddowg\JsonApiLaravel\Authorization\Authorizer;

/**
 * Contributes a custom action's URL as an out-of-band `links` member on every rendered
 * resource of its mount type — core's {@see ResourceLinkContributorInterface} seam — when
 * the action declared `asLink: true`, or when the type's resource declares an
 * {@see ActionLink} for the action's path in its `links()` (ADR 0064). The Laravel twin of
 * the bundle's `Action\ActionLinkContributor`.
 *
 * It is **per server**: each declared server's memoized {@see \haddowg\JsonApi\Server\Server}
 * is threaded its own contributor (via
 * {@see \haddowg\JsonApi\Server\Server::withResourceLinkContributor()}), holding only that
 * server's `asLink` actions — so an action exposed on server A never leaks a link onto
 * server B's resources, and the URL resolves through that server's namespaced route.
 *
 * It is **ability-aware**: when an action declares an `ability`, the link renders only when
 * the requester would pass that gate, evaluated through the SAME {@see Authorizer} the
 * per-action before-gate uses — so a client never sees a link to an action it cannot invoke.
 * An action with no ability always renders its link; a declared ability nothing defines
 * denies, hiding the link, so link visibility matches invocation exactly.
 *
 * It is also **state-aware** when the action's handler is {@see ConditionallyLinked}: the link
 * additionally renders only when the handler's `shouldLink()` accepts the rendered entity (the
 * synthesized soft-delete `restore` link renders only on a trashed resource). The handler is
 * resolved lazily — and only for a `conditionallyLinked` descriptor — through the injected
 * resolver, and memoized per class (handlers are stateless).
 *
 * A declared {@see ActionLink} adds its own `when()` condition, run after the ability gate
 * with the rendered object and the request. It wins over `asLink`, so its condition applies
 * even when the action also sets `asLink`.
 *
 * The href is the action route's path relative to the server, `/{uriType}/{id}/-actions/{path}`,
 * and core prefixes it with the same base as the resource's `self` link: the configured
 * `base_uri`, or the request origin plus the route prefix when that is empty (ADR 0028).
 * Generating it through the URL generator instead would base it on the request host and
 * ignore a configured `base_uri`. The serializer is resolved LAZILY at render time (via the injected resolver closure) so
 * building the Server — which is threaded this contributor — does not recurse.
 */
final class ActionLinkContributor implements ResourceLinkContributorInterface
{
    /**
     * Memoized conditionally-linked handlers, keyed by handler class-string (handlers are
     * stateless, so one instance serves every render).
     *
     * @var array<class-string<ActionHandlerInterface>, ActionHandlerInterface>
     */
    private array $handlers = [];

    /**
     * Memoized declared action links, keyed by mount type then by action path.
     *
     * @var array<string, array<string, ActionLink>>
     */
    private array $actionLinks = [];

    /**
     * @param array<string, list<ActionDescriptor>>                                  $linksByType         this server's resource-scope action descriptors, keyed by mount JSON:API type
     * @param \Closure(string): SerializerInterface                                  $serializerResolver  resolves a type's serializer lazily at render time (avoids a build-time cycle with the Server)
     * @param \Closure(class-string<ActionHandlerInterface>): ActionHandlerInterface $handlerResolver     resolves a conditionally-linked action's handler lazily at render time
     * @param (\Closure(string): list<ActionLink>)|null                              $actionLinksResolver resolves a type's declared action links lazily at render time; null declares none
     */
    public function __construct(
        private readonly array $linksByType,
        private readonly \Closure $serializerResolver,
        private readonly Authorizer $authorizer,
        private readonly \Closure $handlerResolver,
        private readonly ?\Closure $actionLinksResolver = null,
    ) {}

    public function linksFor(mixed $object, string $type, JsonApiRequestInterface $request): array
    {
        $descriptors = $this->linksByType[$type] ?? [];
        if ($descriptors === []) {
            return [];
        }

        $declared = $this->actionLinksFor($type);
        $descriptors = \array_filter(
            $descriptors,
            static fn(ActionDescriptor $descriptor): bool => $descriptor->asLink || isset($declared[$descriptor->path]),
        );
        if ($descriptors === []) {
            return [];
        }

        $serializer = ($this->serializerResolver)($type);
        $id = $serializer->getId($object);
        if ($id === '') {
            // A not-yet-persisted resource (rendered mid-create) has no id to build the
            // action URL from — skip, mirroring core's convention self link.
            return [];
        }

        $uriType = $serializer instanceof UriTypeAwareInterface && $serializer->uriType() !== '' ? $serializer->uriType() : $type;

        $links = [];
        foreach ($descriptors as $descriptor) {
            if ($descriptor->ability !== null && !$this->authorizer->allowsAction($type, $descriptor->ability, \is_object($object) ? $object : null, $descriptor->server)) {
                continue;
            }

            // A conditionally-linked action gates the link on the rendered entity's state
            // (the restore link only on a trashed resource). Skip when there is no object to
            // judge, or the handler's shouldLink() rejects it.
            if ($descriptor->conditionallyLinked && !$this->shouldLink($descriptor, $object)) {
                continue;
            }
            if (isset($declared[$descriptor->path]) && !$declared[$descriptor->path]->appliesTo($object, $request)) {
                continue;
            }

            // Names the action's operation, by its first declared method as the OpenAPI
            // projection does, so the link can carry a `describedby`.
            $links[$descriptor->path] = new OperationLink(
                '/' . $uriType . '/' . $id . '/-actions/' . $descriptor->path,
                OperationReference::action($uriType, $descriptor->path, OpenApiActionScope::Resource, $descriptor->methods[0] ?? 'POST'),
            );
        }

        return $links;
    }

    /**
     * The type's declared action links, keyed by action path, resolved once per type.
     *
     * @return array<string, ActionLink>
     */
    private function actionLinksFor(string $type): array
    {
        if (!isset($this->actionLinks[$type])) {
            $this->actionLinks[$type] = [];
            foreach ($this->actionLinksResolver === null ? [] : ($this->actionLinksResolver)($type) as $link) {
                $this->actionLinks[$type][$link->path] = $link;
            }
        }

        return $this->actionLinks[$type];
    }

    /**
     * Whether a {@see ConditionallyLinked} action's link should render for `$object`, resolving
     * (and memoizing) its handler lazily. A non-object render, or a handler that turns out not
     * to be conditionally-linked, is treated as "do not render".
     */
    private function shouldLink(ActionDescriptor $descriptor, mixed $object): bool
    {
        if (!\is_object($object)) {
            return false;
        }

        $handler = $this->handlers[$descriptor->handlerClass] ??= ($this->handlerResolver)($descriptor->handlerClass);

        return $handler instanceof ConditionallyLinked && $handler->shouldLink($object);
    }
}
