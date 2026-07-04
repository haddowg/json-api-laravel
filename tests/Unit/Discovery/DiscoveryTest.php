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
        // A snapshot built exactly as the Phase 4 optimize pipeline will write one:
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
}
