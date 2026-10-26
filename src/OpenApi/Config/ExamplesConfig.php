<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Config;

/**
 * Reads whether a server's OpenAPI document carries generated operation examples.
 *
 * A server's own `jsonapi.servers.<name>.openapi_examples` wins when it is a boolean. Null or
 * absent inherits the global `jsonapi.openapi.examples`, which defaults to true.
 *
 * @internal
 */
final class ExamplesConfig
{
    /**
     * @param array<array-key, mixed> $servers the `jsonapi.servers` config
     *
     * @throws \InvalidArgumentException when the value in effect is not a boolean or null
     */
    public static function forServer(mixed $global, array $servers, string $server): bool
    {
        $own = self::own($servers, $server);
        if ($own !== null) {
            return self::validated($own, \sprintf('jsonapi.servers.%s.openapi_examples', $server));
        }

        return $global === null || self::validated($global, 'jsonapi.openapi.examples');
    }

    /**
     * Checks every configured server, so a typo fails while the app boots rather than when
     * the document is first built.
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
            self::validated($global, 'jsonapi.openapi.examples');
        }
    }

    /**
     * @param array<array-key, mixed> $servers
     */
    private static function own(array $servers, string $server): mixed
    {
        $config = $servers[$server] ?? null;

        return \is_array($config) ? ($config['openapi_examples'] ?? null) : null;
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
