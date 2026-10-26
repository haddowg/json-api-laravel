<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

/**
 * Reads whether a server refuses a write body that sends a read-only attribute.
 *
 * A server's own `jsonapi.servers.<name>.reject_read_only_attributes` wins when it is a
 * boolean. Null or absent inherits the global `jsonapi.reject_read_only_attributes`, which
 * defaults to false: such attributes are ignored.
 *
 * @internal
 */
final class ReadOnlyAttributesConfig
{
    private const string KEY = 'reject_read_only_attributes';

    /**
     * @param array<array-key, mixed> $servers the `jsonapi.servers` config
     *
     * @throws \InvalidArgumentException when the value in effect is not a boolean or null
     */
    public static function forServer(mixed $global, array $servers, string $server): bool
    {
        $config = $servers[$server] ?? null;
        $own = \is_array($config) ? ($config[self::KEY] ?? null) : null;

        if ($own !== null) {
            return self::validated($own, \sprintf('jsonapi.servers.%s.%s', $server, self::KEY));
        }

        return $global !== null && self::validated($global, 'jsonapi.' . self::KEY);
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
            self::validated($global, 'jsonapi.' . self::KEY);
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
