<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Serializer\ResourceLinkContributorInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiLaravel\Authorization\Authorizer;
use haddowg\JsonApiLaravel\Routing\RouteRegistrar;
use Illuminate\Contracts\Routing\UrlGenerator;

/**
 * Contributes a custom action's URL as an out-of-band `links` member on every rendered
 * resource of its mount type — core's {@see ResourceLinkContributorInterface} seam — when
 * the action declared `asLink: true` (the Laravel twin of the bundle's
 * `Action\ActionLinkContributor`).
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
 * An action with no ability always renders its link; a type with no policy is inert (the
 * gate always passes), so link visibility matches invocation exactly.
 *
 * It is also **state-aware** when the action's handler is {@see ConditionallyLinked}: the link
 * additionally renders only when the handler's `shouldLink()` accepts the rendered entity (the
 * synthesized soft-delete `restore` link renders only on a trashed resource). The handler is
 * resolved lazily — and only for a `conditionallyLinked` descriptor — through the injected
 * resolver, and memoized per class (handlers are stateless).
 *
 * The URL is generated through Laravel's {@see UrlGenerator} for the action's named route,
 * with the rendered object's id (resolved through the serializer the render uses) as the
 * `{id}` parameter, as an absolute URL — matching the request-host-absolute `self` link.
 * The serializer is resolved LAZILY at render time (via the injected resolver closure) so
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
     * @param array<string, list<ActionDescriptor>>                                  $linksByType        this server's `asLink` action descriptors, keyed by mount JSON:API type
     * @param \Closure(string): SerializerInterface                                  $serializerResolver resolves a type's serializer lazily at render time (avoids a build-time cycle with the Server)
     * @param \Closure(class-string<ActionHandlerInterface>): ActionHandlerInterface $handlerResolver    resolves a conditionally-linked action's handler lazily at render time
     */
    public function __construct(
        private readonly array $linksByType,
        private readonly \Closure $serializerResolver,
        private readonly UrlGenerator $urls,
        private readonly Authorizer $authorizer,
        private readonly \Closure $handlerResolver,
    ) {}

    public function linksFor(mixed $object, string $type, JsonApiRequestInterface $request): array
    {
        $descriptors = $this->linksByType[$type] ?? [];
        if ($descriptors === []) {
            return [];
        }

        $id = ($this->serializerResolver)($type)->getId($object);
        if ($id === '') {
            // A not-yet-persisted resource (rendered mid-create) has no id to build the
            // action URL from — skip, mirroring core's convention self link.
            return [];
        }

        $links = [];
        foreach ($descriptors as $descriptor) {
            if ($descriptor->ability !== null && !$this->authorizer->allowsAction($type, $descriptor->ability, \is_object($object) ? $object : null)) {
                continue;
            }

            // A conditionally-linked action gates the link on the rendered entity's state
            // (the restore link only on a trashed resource). Skip when there is no object to
            // judge, or the handler's shouldLink() rejects it.
            if ($descriptor->conditionallyLinked && !$this->shouldLink($descriptor, $object)) {
                continue;
            }

            $url = $this->urls->route(RouteRegistrar::actionRouteName($descriptor), ['id' => $id], true);
            $links[$descriptor->path] = new Link($url);
        }

        return $links;
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
