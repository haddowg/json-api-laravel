<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

/**
 * Reads and checks a response-header `defaults` block (ADR 0073): the global
 * `jsonapi.defaults` or a server's own `jsonapi.servers.<name>.defaults`. Both take the
 * same keys and fail the boot on the same bad values, the ones the bundle's config tree
 * rejects. A null or absent value is unset, so a server block replaces only the global
 * values it sets.
 *
 * @internal
 */
final class ResponseHeaderDefaultsConfig
{
    private const array KEYS = ['cache_headers', 'deprecation', 'sunset', 'sunset_link'];

    private const array INTEGER_DIRECTIVES = ['max_age', 's_maxage'];

    private const array BOOLEAN_DIRECTIVES = ['public', 'private', 'no_cache', 'must_revalidate'];

    /**
     * The scalar `cache` and `deprecation` maps of one block, in the shape the
     * {@see CacheHeaders} and {@see DeprecationHeaders} `fromArray()` factories read.
     *
     * @throws \InvalidArgumentException when the block holds a bad value
     *
     * @return array{cache: array<string, mixed>, deprecation: array<string, mixed>}
     */
    public static function fromBlock(mixed $block, string $key): array
    {
        if ($block === null) {
            return ['cache' => [], 'deprecation' => []];
        }
        if (!\is_array($block)) {
            throw self::invalid($key, 'must be an array or null', $block);
        }

        foreach (\array_keys($block) as $name) {
            if (!\in_array($name, self::KEYS, true)) {
                throw new \InvalidArgumentException(\sprintf(
                    '%s.%s is not a response-header default; use one of %s.',
                    $key,
                    $name,
                    \implode(', ', self::KEYS),
                ));
            }
        }

        $deprecation = [];
        $value = $block['deprecation'] ?? null;
        if ($value !== null) {
            if (!\is_bool($value) && !\is_string($value)) {
                throw self::invalid($key . '.deprecation', 'must be true, false, a date string or null', $value);
            }
            $deprecation['deprecation'] = $value;
        }
        foreach (['sunset', 'sunset_link'] as $name) {
            $value = $block[$name] ?? null;
            if ($value !== null) {
                if (!\is_string($value)) {
                    throw self::invalid($key . '.' . $name, 'must be a string or null', $value);
                }
                $deprecation[$name] = $value;
            }
        }

        return ['cache' => self::cache($block['cache_headers'] ?? null, $key . '.cache_headers'), 'deprecation' => $deprecation];
    }

    /**
     * Each configured server's own block, keyed by server; a server that sets nothing is
     * left out and reads the global defaults.
     *
     * @param array<array-key, mixed> $servers the `jsonapi.servers` config
     *
     * @throws \InvalidArgumentException when a block holds a bad value
     *
     * @return array<string, array{cache: array<string, mixed>, deprecation: array<string, mixed>}>
     */
    public static function forServers(array $servers): array
    {
        $byServer = [];
        foreach ($servers as $server => $config) {
            $block = self::fromBlock(\is_array($config) ? ($config['defaults'] ?? null) : null, \sprintf('jsonapi.servers.%s.defaults', $server));
            if ($block['cache'] !== [] || $block['deprecation'] !== []) {
                $byServer[(string) $server] = $block;
            }
        }

        return $byServer;
    }

    /**
     * Checks the global block and every server's, so a typo fails while the app boots
     * rather than on the first JSON:API request.
     *
     * @param array<array-key, mixed> $servers the `jsonapi.servers` config
     *
     * @throws \InvalidArgumentException when any block holds a bad value
     */
    public static function assertValid(mixed $global, array $servers): void
    {
        self::fromBlock($global, 'jsonapi.defaults');
        self::forServers($servers);
    }

    /**
     * @return array<string, mixed>
     */
    private static function cache(mixed $cache, string $key): array
    {
        if ($cache === null) {
            return [];
        }
        if (!\is_array($cache)) {
            throw self::invalid($key, 'must be an array or null', $cache);
        }

        $directives = [];
        foreach ($cache as $name => $value) {
            $name = (string) $name;
            if (\in_array($name, self::INTEGER_DIRECTIVES, true)) {
                if ($value !== null && !\is_int($value)) {
                    throw self::invalid($key . '.' . $name, 'must be an integer or null', $value);
                }
            } elseif (\in_array($name, self::BOOLEAN_DIRECTIVES, true)) {
                if ($value !== null && !\is_bool($value)) {
                    throw self::invalid($key . '.' . $name, 'must be true, false or null', $value);
                }
            } elseif ($name === 'vary') {
                if ($value !== null && (!\is_array($value) || !\array_is_list($value) || \array_filter($value, static fn(mixed $header): bool => !\is_string($header)) !== [])) {
                    throw self::invalid($key . '.vary', 'must be a list of header names or null', $value);
                }
            } else {
                throw new \InvalidArgumentException(\sprintf(
                    '%s.%s is not a cache directive; use one of %s, vary.',
                    $key,
                    $name,
                    \implode(', ', [...self::INTEGER_DIRECTIVES, ...self::BOOLEAN_DIRECTIVES]),
                ));
            }

            if ($value !== null && $value !== []) {
                $directives[$name] = $value;
            }
        }

        return $directives;
    }

    private static function invalid(string $key, string $rule, mixed $value): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf('%s %s, got %s.', $key, $rule, \get_debug_type($value)));
    }
}
