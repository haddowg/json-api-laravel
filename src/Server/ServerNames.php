<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Server\ResourceRegistry;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Operation\TargetResolver;

/**
 * The configured name of a server an operation runs on. A request routed through the
 * controller carries it as the `_jsonapi_server` attribute. A programmatic dispatch may not,
 * so the name is then read off the server itself: each {@see ServerFactory} records the
 * resource registry of the server it builds, which every copy of that server shares. A
 * server no factory built is the `default` server.
 */
final class ServerNames
{
    /**
     * @var \WeakMap<ResourceRegistry, string>
     */
    private \WeakMap $names;

    public function __construct()
    {
        $this->names = new \WeakMap();
    }

    public function register(Server $server, string $name): void
    {
        $this->names[$server->resources()] = $name;
    }

    /**
     * The name the request carries, else the name of the server, else `default`.
     */
    public function resolve(?JsonApiRequestInterface $request, mixed $server = null): string
    {
        $name = $request?->getAttribute(TargetResolver::SERVER_ATTRIBUTE);
        if (\is_string($name) && $name !== '') {
            return $name;
        }

        return ($server instanceof Server ? $this->names[$server->resources()] ?? null : null) ?? ServerRegistry::DEFAULT_SERVER;
    }
}
