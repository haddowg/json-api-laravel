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
 * The URL is generated through Laravel's {@see UrlGenerator} for the action's named route,
 * with the rendered object's id (resolved through the serializer the render uses) as the
 * `{id}` parameter, as an absolute URL — matching the request-host-absolute `self` link.
 * The serializer is resolved LAZILY at render time (via the injected resolver closure) so
 * building the Server — which is threaded this contributor — does not recurse.
 */
final readonly class ActionLinkContributor implements ResourceLinkContributorInterface
{
    /**
     * @param array<string, list<ActionDescriptor>>       $linksByType        this server's `asLink` action descriptors, keyed by mount JSON:API type
     * @param \Closure(string): SerializerInterface       $serializerResolver resolves a type's serializer lazily at render time (avoids a build-time cycle with the Server)
     */
    public function __construct(
        private array $linksByType,
        private \Closure $serializerResolver,
        private UrlGenerator $urls,
        private Authorizer $authorizer,
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

            $url = $this->urls->route(RouteRegistrar::actionRouteName($descriptor), ['id' => $id], true);
            $links[$descriptor->path] = new Link($url);
        }

        return $links;
    }
}
