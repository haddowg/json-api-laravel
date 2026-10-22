<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Builds each server's container resolver so no two servers hold the same resource.
 *
 * Core's registry injects itself into every resource it resolves, and the resource then
 * reads server settings off it: the output timezone, the action-link contributor and the
 * relationship seams. When the container hands two servers one shared instance, such as a
 * resource bound as a singleton, the server that resolved it last would decide those
 * settings for both. The first server keeps the container's instance; a later server gets
 * a shallow clone, so the resource's own dependencies are still the ones the container
 * injected.
 *
 * @internal
 */
final class PerServerInstances
{
    /**
     * @var \WeakMap<object, string> the server each resolver-aware instance was handed to
     */
    private \WeakMap $owners;

    public function __construct(private readonly Container $container)
    {
        $this->owners = new \WeakMap();
    }

    /**
     * @return \Closure(class-string): object
     */
    public function resolverFor(string $server): \Closure
    {
        return function (string $class) use ($server): object {
            $instance = $this->container->make($class);
            \assert(\is_object($instance));

            if (!$instance instanceof SerializerResolverAwareInterface) {
                return $instance;
            }

            $owner = $this->owners[$instance] ?? null;
            if ($owner !== null && $owner !== $server) {
                $instance = clone $instance;
            }
            $this->owners[$instance] = $server;

            return $instance;
        };
    }
}
