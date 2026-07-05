<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Discovery;

use haddowg\JsonApiLaravel\Attribute\AsJsonApiHydrator;
use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\Discovery\DiscoverySnapshotWriter;
use haddowg\JsonApiLaravel\Discovery\HydratorDescriptor;
use haddowg\JsonApiLaravel\Discovery\SerializerDescriptor;
use haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator\BeaconHydrator;
use haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator\BeaconSerializer;
use haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator\PulseCapability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The standalone-hydrator discovery channel (the write twin of the serializer channel,
 * bundle ADR 0024): classification off `#[AsJsonApiHydrator]`, the dual-attribute
 * (one class, both halves) case, the descriptor's cacheable array form, and the
 * jsonapi:optimize snapshot round trip.
 *
 * @internal
 */
#[CoversClass(DiscoveryScanner::class)]
#[CoversClass(HydratorDescriptor::class)]
#[CoversClass(AsJsonApiHydrator::class)]
#[CoversClass(DiscoverySnapshotWriter::class)]
final class StandaloneHydratorDiscoveryTest extends TestCase
{
    public function test_it_classifies_a_standalone_hydrator_from_its_attribute(): void
    {
        // A HydratorInterface carrying #[AsJsonApiHydrator] and no AbstractResource is the
        // decoupled write half: it lands in the hydrators bucket, never among the
        // resources or the serializers.
        $result = (new DiscoveryScanner())->scan([], [BeaconHydrator::class]);

        self::assertSame([], $result->resources);
        self::assertSame([], $result->serializers);
        self::assertCount(1, $result->hydrators);

        $beacons = $result->hydrators[0];
        self::assertSame(BeaconHydrator::class, $beacons->class);
        self::assertSame('beacons', $beacons->type);
        self::assertSame(['default'], $beacons->servers);
        self::assertTrue($beacons->exposedOn('default'));
        self::assertFalse($beacons->exposedOn('admin'));
    }

    public function test_a_dual_attribute_class_lands_in_both_buckets(): void
    {
        // One class implementing both interfaces may carry both attributes, registering
        // both halves of a resource-less type in one place (the bundle's dual-attribute
        // form): the scan yields a serializer descriptor AND a hydrator descriptor for it.
        $result = (new DiscoveryScanner())->scan([], [PulseCapability::class]);

        self::assertCount(1, $result->serializers);
        self::assertCount(1, $result->hydrators);
        self::assertSame(PulseCapability::class, $result->serializers[0]->class);
        self::assertSame(PulseCapability::class, $result->hydrators[0]->class);
        self::assertSame('pulses', $result->serializers[0]->type);
        self::assertSame('pulses', $result->hydrators[0]->type);
        // The dual-attribute class's serializer half declared no allow-list: the
        // serialize-only default stays empty (the hydrator adds write CAPABILITY, never
        // endpoints of its own).
        self::assertSame([], $result->serializers[0]->operations);
    }

    public function test_a_hydrator_descriptor_round_trips_through_its_array_form(): void
    {
        $descriptor = new HydratorDescriptor(
            BeaconHydrator::class,
            'beacons',
            ['default', 'admin'],
        );

        self::assertEquals($descriptor, HydratorDescriptor::fromArray($descriptor->toArray()));
    }

    public function test_the_optimize_snapshot_round_trips_the_hydrator_channel(): void
    {
        // The WRITE side (DiscoverySnapshotWriter) must carry the hydrators alongside the
        // serializers, and the READ side (Discovery's snapshot loader) must rebuild them —
        // otherwise a jsonapi:optimize'd production app silently drops every standalone
        // hydrator and its types' writes 500 at runtime.
        $live = new Discovery(new DiscoveryScanner(), [], [BeaconSerializer::class, BeaconHydrator::class]);

        $file = \tempnam(\sys_get_temp_dir(), 'jsonapi-snapshot-');
        self::assertIsString($file);

        try {
            (new DiscoverySnapshotWriter($live))->write($file);

            $cached = new Discovery(new DiscoveryScanner(), [], [], $file);

            self::assertEquals($live->serializers(), $cached->serializers());
            self::assertEquals($live->hydrators(), $cached->hydrators());
            self::assertCount(1, $cached->hydrators());
            self::assertSame(BeaconHydrator::class, $cached->hydrators()[0]->class);
            self::assertSame(
                [BeaconHydrator::class],
                \array_map(static fn(HydratorDescriptor $d): string => $d->class, $cached->hydratorsFor('default')),
            );
        } finally {
            @\unlink($file);
        }
    }

    public function test_a_legacy_snapshot_without_the_hydrators_key_degrades_to_none(): void
    {
        // A snapshot written before the hydrator channel existed still loads — the
        // missing key degrades to an empty list, matching every other channel's
        // graceful-degradation rule.
        $serializer = new SerializerDescriptor(BeaconSerializer::class, 'beacons', 'beacons', ['default'], []);

        $file = \tempnam(\sys_get_temp_dir(), 'jsonapi-snapshot-');
        self::assertIsString($file);
        \file_put_contents($file, '<?php return ' . \var_export(['serializers' => [$serializer->toArray()]], true) . ';');

        try {
            $discovery = new Discovery(new DiscoveryScanner(), [], [], $file);

            self::assertCount(1, $discovery->serializers());
            self::assertSame([], $discovery->hydrators());
        } finally {
            @\unlink($file);
        }
    }
}
