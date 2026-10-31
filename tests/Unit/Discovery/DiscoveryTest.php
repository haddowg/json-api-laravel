<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Discovery;

use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\Discovery\ResourceDescriptor;
use haddowg\JsonApiLaravel\Operation\Operation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Workbench\App\JsonApi\ArtistResource;

/**
 * @internal
 */
#[CoversClass(Discovery::class)]
final class DiscoveryTest extends TestCase
{
    public function test_a_snapshot_cache_restores_resources_providers_and_persisters(): void
    {
        // A snapshot built exactly as the optimize pipeline writes one:
        // resource array forms + plain provider/persister class-strings, var_export'ed
        // to a requirable PHP file. The read side must round-trip ALL THREE, not just
        // the resources — otherwise a cached (production) config silently drops every
        // scanned provider/persister and every request for their types 500s.
        $descriptor = new ResourceDescriptor(
            ArtistResource::class,
            'artists',
            'artists',
            ['default'],
            [Operation::FetchOne->value],
        );

        $snapshot = [
            'resources' => [$descriptor->toArray()],
            'providers' => ['App\\JsonApi\\CatalogProvider'],
            'persisters' => ['App\\JsonApi\\CatalogPersister'],
        ];

        $file = \tempnam(\sys_get_temp_dir(), 'jsonapi-snapshot-');
        self::assertIsString($file);
        \file_put_contents($file, '<?php return ' . \var_export($snapshot, true) . ';');

        try {
            $discovery = new Discovery(new DiscoveryScanner(), [], [], $file);

            $types = \array_map(static fn(ResourceDescriptor $d): string => $d->type, $discovery->resources());

            self::assertSame(['artists'], $types);
            self::assertSame(['App\\JsonApi\\CatalogProvider'], $discovery->providers());
            self::assertSame(['App\\JsonApi\\CatalogPersister'], $discovery->persisters());
        } finally {
            @\unlink($file);
        }
    }

    public function test_a_resources_only_snapshot_yields_empty_provider_and_persister_lists(): void
    {
        // A legacy/partial snapshot missing the SPI keys degrades gracefully rather
        // than erroring — resources still load, SPI lists are empty.
        $descriptor = new ResourceDescriptor(ArtistResource::class, 'artists', 'artists', ['default'], [Operation::FetchOne->value]);

        $file = \tempnam(\sys_get_temp_dir(), 'jsonapi-snapshot-');
        self::assertIsString($file);
        \file_put_contents($file, '<?php return ' . \var_export(['resources' => [$descriptor->toArray()]], true) . ';');

        try {
            $discovery = new Discovery(new DiscoveryScanner(), [], [], $file);

            self::assertCount(1, $discovery->resources());
            self::assertSame([], $discovery->providers());
            self::assertSame([], $discovery->persisters());
        } finally {
            @\unlink($file);
        }
    }

    public function test_a_snapshot_carries_the_model_map_for_the_namespace_it_was_resolved_under(): void
    {
        $file = $this->snapshot([
            'models' => ['namespace' => 'App\\Models', 'map' => ['artists' => 'App\\Models\\Artist']],
        ]);

        try {
            $discovery = new Discovery(new DiscoveryScanner(), [], [], $file);

            self::assertSame(['artists' => 'App\\Models\\Artist'], $discovery->modelMap('App\\Models'));
            // Resolved under another namespace, the map no longer applies.
            self::assertNull($discovery->modelMap('Domain\\Models'));
            self::assertNull($discovery->modelMap(null));
        } finally {
            @\unlink($file);
        }
    }

    public function test_a_snapshot_written_before_the_model_map_existed_has_none(): void
    {
        $file = $this->snapshot([]);

        try {
            $discovery = new Discovery(new DiscoveryScanner(), [], [], $file);

            self::assertCount(1, $discovery->resources());
            self::assertNull($discovery->modelMap('App\\Models'));
        } finally {
            @\unlink($file);
        }
    }

    public function test_a_malformed_model_map_is_ignored(): void
    {
        $file = $this->snapshot([
            'models' => ['namespace' => 'App\\Models', 'map' => ['artists' => 42]],
        ]);

        try {
            $discovery = new Discovery(new DiscoveryScanner(), [], [], $file);

            self::assertNull($discovery->modelMap('App\\Models'));
        } finally {
            @\unlink($file);
        }
    }

    public function test_a_live_scan_has_no_model_map(): void
    {
        $discovery = new Discovery(new DiscoveryScanner(), []);

        self::assertNull($discovery->modelMap('App\\Models'));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function snapshot(array $extra): string
    {
        $descriptor = new ResourceDescriptor(ArtistResource::class, 'artists', 'artists', ['default'], [Operation::FetchOne->value]);

        $file = \tempnam(\sys_get_temp_dir(), 'jsonapi-snapshot-');
        self::assertIsString($file);
        \file_put_contents($file, '<?php return ' . \var_export(['resources' => [$descriptor->toArray()]] + $extra, true) . ';');

        return $file;
    }

    public function test_rescan_drops_the_snapshot_and_scans_the_configured_paths(): void
    {
        $file = $this->snapshot([
            'models' => ['namespace' => 'App\\Models', 'map' => ['artists' => 'App\\Models\\Artist']],
        ]);

        try {
            $discovery = new Discovery(new DiscoveryScanner(), [\dirname(__DIR__, 2) . '/Fixtures/ModelMap/JsonApi'], [], $file);
            self::assertSame(['artists'], $this->types($discovery));

            $discovery->rescan();

            self::assertEqualsCanonicalizing(['imports', 'pressings', 'recordings'], $this->types($discovery));
            self::assertNull($discovery->modelMap('App\\Models'));
        } finally {
            @\unlink($file);
        }
    }

    /**
     * @return list<string>
     */
    private function types(Discovery $discovery): array
    {
        return \array_map(static fn(ResourceDescriptor $d): string => $d->type, $discovery->resources());
    }
}
