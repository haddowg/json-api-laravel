<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Discovery;

use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\Discovery\ResourceDescriptor;
use haddowg\JsonApiLaravel\Discovery\SerializerDescriptor;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApiLaravel\Tests\Fixtures\Action\AsyncPollHandler;
use haddowg\JsonApiLaravel\Tests\Fixtures\Action\MetaHandlerMissingOutputMeta;
use haddowg\JsonApiLaravel\Tests\Fixtures\Action\MetaHandlerWithOutputMeta;
use haddowg\JsonApiLaravel\Tests\Fixtures\Action\NoContentHandlerMissingReturns204;
use haddowg\JsonApiLaravel\Tests\Fixtures\Action\UnionReturnHandler;
use haddowg\JsonApiLaravel\Tests\Fixtures\Overrides\BadSerializerOverrideResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\Overrides\MemoHydrator;
use haddowg\JsonApiLaravel\Tests\Fixtures\Overrides\MemoResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\Overrides\NoteResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\Overrides\NoteSerializer;
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

    public function test_it_carries_the_serializer_and_hydrator_overrides_off_the_attribute(): void
    {
        // #[AsJsonApiResource(serializer:/hydrator:)] overrides land on the descriptor as
        // plain class-strings (ADR 0014), each concern independently — the descriptor for
        // a serializer-only override keeps a null hydrator, and vice versa.
        $result = (new DiscoveryScanner())->scan([], [NoteResource::class, MemoResource::class]);

        self::assertCount(2, $result->resources);
        [$notes, $memos] = $result->resources;

        self::assertSame('notes', $notes->type);
        self::assertSame(NoteSerializer::class, $notes->serializer);
        self::assertNull($notes->hydrator);

        self::assertSame('memos', $memos->type);
        self::assertNull($memos->serializer);
        self::assertSame(MemoHydrator::class, $memos->hydrator);
    }

    public function test_an_override_not_implementing_its_contract_fails_discovery(): void
    {
        // A serializer: override naming a class without core's SerializerInterface must
        // fail the scan loudly (the Laravel twin of the bundle compiler pass's guard),
        // not surface later as a runtime resolver error.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('serializer');

        (new DiscoveryScanner())->scan([], [BadSerializerOverrideResource::class]);
    }

    public function test_a_descriptor_with_overrides_round_trips_through_its_array_form(): void
    {
        // The overrides must survive the optimize snapshot (toArray/fromArray), and a
        // legacy snapshot without the keys degrades to no override.
        $descriptor = new ResourceDescriptor(
            NoteResource::class,
            'notes',
            'notes',
            ['default'],
            [Operation::FetchOne->value],
            serializer: NoteSerializer::class,
            hydrator: MemoHydrator::class,
        );

        self::assertEquals($descriptor, ResourceDescriptor::fromArray($descriptor->toArray()));

        $legacy = $descriptor->toArray();
        unset($legacy['serializer'], $legacy['hydrator']);
        $degraded = ResourceDescriptor::fromArray($legacy);

        self::assertNull($degraded->serializer);
        self::assertNull($degraded->hydrator);
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

    public function test_an_action_handler_narrowing_to_meta_response_without_output_meta_fails_discovery(): void
    {
        // The handle() return type is narrowed to exactly MetaResponse, but the attribute
        // declares no MetaResult in its responds: the projected shape would drift from what
        // the handler returns, so discovery must fail loudly (the Laravel twin of the
        // bundle's guardActionHandlerOutput).
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('MetaResult');

        (new DiscoveryScanner())->scan([], [MetaHandlerMissingOutputMeta::class]);
    }

    public function test_an_action_handler_narrowing_to_no_content_without_returns_204_fails_discovery(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('new NoContent()');

        (new DiscoveryScanner())->scan([], [NoContentHandlerMissingReturns204::class]);
    }

    public function test_an_action_handler_with_a_matching_output_flag_passes_discovery(): void
    {
        // MetaResponse narrowing + responds: [new MetaResult()] — the declared shape agrees
        // with the projected shape, so the action's responds carries the meta result.
        $result = (new DiscoveryScanner())->scan([], [MetaHandlerWithOutputMeta::class]);

        self::assertCount(1, $result->actions);
        self::assertSame([['kind' => 'meta', 'ref' => null]], $result->actions[0]->responds);
    }

    public function test_an_action_handler_with_an_un_narrowed_return_type_passes_without_flags(): void
    {
        // The handle() return keeps the interface's union (not a single response class), so
        // it declares no single body shape: the guard does not constrain it and it defaults
        // to a 200 document of the mount type.
        $result = (new DiscoveryScanner())->scan([], [UnionReturnHandler::class]);

        self::assertCount(1, $result->actions);
        self::assertSame([['kind' => 'resource', 'ref' => 'guarded']], $result->actions[0]->responds);
    }

    public function test_an_async_poll_action_carries_its_accepted_and_see_other_responses(): void
    {
        // responds: [new Accepted('guarded-jobs'), new SeeOther()] — the union return leaves the
        // output guard unconstrained, and the set round-trips to its cacheable scalar form (a
        // 202 accept + a 303 completion) for the OpenAPI projection.
        $result = (new DiscoveryScanner())->scan([], [AsyncPollHandler::class]);

        self::assertCount(1, $result->actions);
        self::assertSame(
            [['kind' => 'accepted', 'ref' => 'guarded-jobs'], ['kind' => 'seeother', 'ref' => null]],
            $result->actions[0]->responds,
        );
    }
}
