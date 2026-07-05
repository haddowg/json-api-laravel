<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Discovery;

/**
 * Writes (and clears) the discovery snapshot the {@see Discovery} loader consumes —
 * the WRITE side of the Phase-0 snapshot cache, driven by the optimize pipeline (PLAN
 * decision 11). The loader already reads a `require`-able PHP file at
 * `jsonapi.discovery.cache`; this warmer produces it, so a `route:cache`d production app
 * registers routes + assembles servers from the cached snapshot with no filesystem walk.
 *
 * It writes only the plain, `var_export`-able snapshot the loader expects — the resource
 * descriptors as array forms plus the discovered provider/persister/translator
 * class-strings — so a cached configuration is behaviourally identical to a live scan.
 *
 * The snapshot is **opt-in**: it is written only when `jsonapi.discovery.cache` names a
 * path (null keeps the always-scan default). `php artisan optimize` writes it,
 * `optimize:clear` removes it — matching Laravel's `config:cache` / `route:cache` idiom.
 */
final class DiscoverySnapshotWriter
{
    public function __construct(private readonly Discovery $discovery) {}

    /**
     * Writes the discovery snapshot to `$path` as a `require`-able PHP file returning the
     * loader's `{resources, providers, persisters, translators}` array.
     */
    public function write(string $path): void
    {
        $snapshot = [
            'resources' => \array_map(
                static fn(ResourceDescriptor $descriptor): array => $descriptor->toArray(),
                $this->discovery->resources(),
            ),
            'providers' => $this->discovery->providers(),
            'persisters' => $this->discovery->persisters(),
            'translators' => $this->discovery->translators(),
            'actions' => \array_map(
                static fn(\haddowg\JsonApiLaravel\Action\ActionDescriptor $descriptor): array => $descriptor->toArray(),
                $this->discovery->actions(),
            ),
            'serializers' => \array_map(
                static fn(SerializerDescriptor $descriptor): array => $descriptor->toArray(),
                $this->discovery->serializers(),
            ),
            'hydrators' => \array_map(
                static fn(HydratorDescriptor $descriptor): array => $descriptor->toArray(),
                $this->discovery->hydrators(),
            ),
        ];

        $php = '<?php' . "\n\n" . 'return ' . \var_export($snapshot, true) . ';' . "\n";

        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0o777, true) && !\is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Could not create the discovery cache directory "%s".', $dir));
        }

        if (\file_put_contents($path, $php) === false) {
            throw new \RuntimeException(\sprintf('Could not write the discovery snapshot "%s".', $path));
        }
    }

    /**
     * Removes the discovery snapshot at `$path` (a no-op when absent).
     */
    public function clear(string $path): void
    {
        if (\is_file($path)) {
            @\unlink($path);
        }
    }
}
