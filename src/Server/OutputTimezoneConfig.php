<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

/**
 * Reads the `output_timezone` a server renders `DateTime` attributes in.
 *
 * A server's own `jsonapi.servers.<name>.output_timezone` wins when it is a string, and
 * an empty string there turns conversion off for that server. Null or absent inherits
 * the global `jsonapi.output_timezone`, where an empty string or null means no
 * conversion. These are the same rules a server's `base_uri` follows.
 *
 * @internal
 */
final class OutputTimezoneConfig
{
    /**
     * The zone `$server` renders in, or null for no conversion.
     *
     * @param array<array-key, mixed> $servers the `jsonapi.servers` config
     *
     * @throws \InvalidArgumentException when the value in effect is not a timezone PHP recognises
     */
    public static function forServer(mixed $global, array $servers, string $server): ?string
    {
        $config = $servers[$server] ?? null;
        $own = \is_array($config) ? ($config['output_timezone'] ?? null) : null;

        if ($own !== null) {
            return self::validated($own, \sprintf('jsonapi.servers.%s.output_timezone', $server));
        }

        return $global === null ? null : self::validated($global, 'jsonapi.output_timezone');
    }

    /**
     * Checks every configured server, so a typo fails while the app boots rather than on
     * the first JSON:API request.
     *
     * @param array<array-key, mixed> $servers the `jsonapi.servers` config
     *
     * @throws \InvalidArgumentException when any server's zone is not one PHP recognises
     */
    public static function assertValid(mixed $global, array $servers): void
    {
        if ($global !== null) {
            self::validated($global, 'jsonapi.output_timezone');
        }

        foreach (\array_keys($servers) as $server) {
            self::forServer($global, $servers, (string) $server);
        }
    }

    private static function validated(mixed $timezone, string $key): ?string
    {
        if ($timezone === '') {
            return null;
        }

        if (\is_string($timezone)) {
            try {
                new \DateTimeZone($timezone);

                return $timezone;
            } catch (\Exception) {
            }
        }

        throw new \InvalidArgumentException(\sprintf(
            '%s is %s, which is not a timezone PHP recognises. Use an identifier such as "UTC" or "Europe/London", or null for no conversion.',
            $key,
            \is_string($timezone) ? '"' . $timezone . '"' : \get_debug_type($timezone),
        ));
    }
}
