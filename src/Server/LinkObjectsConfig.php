<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

/**
 * Reads whether a server renders its built links as link objects.
 *
 * A server's own `jsonapi.servers.<name>.link_objects` wins when it is a boolean. Null or
 * absent inherits the global `jsonapi.link_objects`, which defaults to true.
 *
 * @internal
 */
final class LinkObjectsConfig
{
    /**
     * @param array<array-key, mixed> $servers the `jsonapi.servers` config
     *
     * @throws \InvalidArgumentException when the value in effect is not a boolean or null
     */
    public static function forServer(mixed $global, array $servers, string $server): bool
    {
        $config = $servers[$server] ?? null;
        $own = \is_array($config) ? ($config['link_objects'] ?? null) : null;

        if ($own !== null) {
            return self::validated($own, \sprintf('jsonapi.servers.%s.link_objects', $server));
        }

        return $global === null || self::validated($global, 'jsonapi.link_objects');
    }

    /**
     * Checks every configured server, so a typo fails while the app boots rather than on
     * the first JSON:API request.
     *
     * @param array<array-key, mixed> $servers the `jsonapi.servers` config
     *
     * @throws \InvalidArgumentException when any value is not a boolean or null
     */
    public static function assertValid(mixed $global, array $servers): void
    {
        foreach (\array_keys($servers) as $server) {
            self::forServer($global, $servers, (string) $server);
        }
        if ($global !== null) {
            self::validated($global, 'jsonapi.link_objects');
        }
    }

    private static function validated(mixed $value, string $key): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        throw new \InvalidArgumentException(\sprintf(
            '%s must be true, false or null, got %s.',
            $key,
            \get_debug_type($value),
        ));
    }
}
