<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Server\Server;

/**
 * Resolves a configured {@see Server} by its `_jsonapi_server` route-default name.
 *
 * Multi-server support is config-declared: `jsonapi.servers` declares each server,
 * and one {@see ServerFactory} is built per declared server, each holding only that
 * server's registered resources. This registry holds a name → factory map and
 * resolves the requested server by name. An unknown name is a {@see \LogicException}
 * — a wiring fault — not a runtime `404`.
 */
final class ServerRegistry
{
    public const string DEFAULT_SERVER = 'default';

    /**
     * @param array<string, ServerFactory> $factories keyed by server name
     */
    public function __construct(private readonly array $factories) {}

    /**
     * The Server for `$name`, or the `default` server when `$name` is null.
     *
     * @throws \LogicException when an unknown server name is requested
     */
    public function get(?string $name = null): Server
    {
        $name ??= self::DEFAULT_SERVER;

        if (!isset($this->factories[$name])) {
            throw new \LogicException(\sprintf('No JSON:API server is configured under the name "%s".', $name));
        }

        return $this->factories[$name]->create();
    }
}
