<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Discovery;

use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\Discovery\ResourceDescriptor;
use haddowg\JsonApiLaravel\Discovery\SerializerDescriptor;
use haddowg\JsonApiLaravel\Operation\Operation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Workbench\App\JsonApi\AlbumResource;
use Workbench\App\JsonApi\ArtistResource;
use Workbench\App\MusicCatalog\Serializer\ChartSerializer;
use Workbench\App\MusicCatalog\Serializer\CountrySerializer;

/**
 * @internal
 */
#[CoversClass(DiscoveryScanner::class)]
#[CoversClass(ResourceDescriptor::class)]
#[CoversClass(SerializerDescriptor::class)]
#[CoversClass(AsJsonApiSerializer::class)]
final class DiscoveryScannerTest extends TestCase
{
    public function test_it_describes_a_read_only_resource_from_its_static_declaration(): void
    {
        $result = (new DiscoveryScanner())->scan([], [ArtistResource::class]);

        self::assertCount(1, $result->resources);
        $descriptor = $result->resources[0];

        self::assertSame(ArtistResource::class, $descriptor->class);
        self::assertSame('artists', $descriptor->type);
        self::assertSame('artists', $descriptor->uriType);
        self::assertSame(['default'], $descriptor->servers);
        self::assertSame(
            [Operation::FetchCollection->value, Operation::FetchOne->value],
            $descriptor->operations,
        );
        self::assertTrue($descriptor->exposes(Operation::FetchCollection));
        self::assertFalse($descriptor->exposes(Operation::Create));
        self::assertTrue($descriptor->exposedOn('default'));
    }

    public function test_it_scans_a_directory_and_finds_every_resource(): void
    {
        $result = (new DiscoveryScanner())->scan([\dirname(__DIR__, 3) . '/workbench/app/JsonApi']);

        $types = \array_map(static fn(ResourceDescriptor $d): string => $d->type, $result->resources);
        \sort($types);

        self::assertSame(['albums', 'artists', 'genres'], $types);
    }

    public function test_explicit_classes_are_deduplicated_against_scanned_ones(): void
    {
        $result = (new DiscoveryScanner())->scan(
            [\dirname(__DIR__, 3) . '/workbench/app/JsonApi'],
            [AlbumResource::class],
        );

        $albums = \array_filter($result->resources, static fn(ResourceDescriptor $d): bool => $d->type === 'albums');

        self::assertCount(1, $albums);
    }

    public function test_it_skips_an_anonymous_class_and_finds_the_named_declaration_after_it(): void
    {
        // The fixture file's first `class` token is an anonymous class; the named
        // resource follows. Before the T_NEW guard the scanner read the anonymous
        // class's parent name and returned early, discovering nothing.
        $result = (new DiscoveryScanner())->scan([\dirname(__DIR__, 2) . '/Fixtures/Scan']);

        $types = \array_map(static fn(ResourceDescriptor $d): string => $d->type, $result->resources);

        self::assertSame(['scan-named'], $types);
    }

    public function test_a_descriptor_round_trips_through_its_array_form(): void
    {
        $descriptor = new ResourceDescriptor(
            ArtistResource::class,
            'artists',
            'artists',
            ['default'],
            [Operation::FetchOne->value],
        );

        self::assertEquals($descriptor, ResourceDescriptor::fromArray($descriptor->toArray()));
    }

    public function test_it_classifies_a_standalone_serializer_from_its_attribute(): void
    {
        // A SerializerInterface carrying #[AsJsonApiSerializer] and no AbstractResource is a
        // standalone (resource-less) capability (decision 3, bundle ADR 0024): it lands in the
        // serializers bucket, never among the resources.
        $result = (new DiscoveryScanner())->scan([], [ChartSerializer::class, CountrySerializer::class]);

        self::assertSame([], $result->resources);
        self::assertCount(2, $result->serializers);

        $charts = $result->serializers[0];
        self::assertSame(ChartSerializer::class, $charts->class);
        self::assertSame('charts', $charts->type);
        // A resource-less type's URI segment defaults to its type (the descriptor rule).
        self::assertSame('charts', $charts->uriType);
        self::assertSame(['default'], $charts->servers);
        self::assertSame(
            [Operation::FetchCollection->value, Operation::FetchOne->value],
            $charts->operations,
        );
        self::assertTrue($charts->exposes(Operation::FetchCollection));
        self::assertFalse($charts->exposes(Operation::Create));
        self::assertTrue($charts->exposedOn('default'));
    }

    public function test_a_serializer_descriptor_round_trips_through_its_array_form(): void
    {
        $descriptor = new SerializerDescriptor(
            ChartSerializer::class,
            'charts',
            'charts',
            ['default'],
            [Operation::FetchCollection->value, Operation::FetchOne->value],
            ['Charts'],
        );

        self::assertEquals($descriptor, SerializerDescriptor::fromArray($descriptor->toArray()));
    }
}
