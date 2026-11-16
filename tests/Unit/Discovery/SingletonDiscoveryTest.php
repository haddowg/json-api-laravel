<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Discovery;

use haddowg\JsonApi\Resource\SingletonMisdeclared;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\Discovery\ResourceDescriptor;
use haddowg\JsonApiLaravel\Discovery\SerializerDescriptor;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApiLaravel\Tests\Fixtures\Singleton\DeletableProfileResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\Singleton\ExportProfilesAction;
use haddowg\JsonApiLaravel\Tests\Fixtures\Singleton\ListedProfileResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\Singleton\OverriddenProfileResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\Singleton\ProfileResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\Singleton\ReadOnlyProfileResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\Singleton\RelatedOnlyProfileResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\Singleton\StandaloneSettingsHydrator;
use haddowg\JsonApiLaravel\Tests\Fixtures\Singleton\StandaloneSettingsSerializer;
use haddowg\JsonApiLaravel\Tests\Fixtures\Singleton\UnresolvedProfileResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Discovery reads a singleton from its resource class, or from the serializer that renders
 * it, gives it fetch and update by default, and refuses one it could never serve.
 *
 * @internal
 */
#[CoversClass(DiscoveryScanner::class)]
#[CoversClass(ResourceDescriptor::class)]
#[CoversClass(SerializerDescriptor::class)]
final class SingletonDiscoveryTest extends TestCase
{
    public function test_a_singleton_serves_fetch_and_update_by_default(): void
    {
        $descriptor = (new DiscoveryScanner())->scan([], [ProfileResource::class])->resources[0];

        self::assertTrue($descriptor->singleton);
        self::assertSame([Operation::FetchOne->value, Operation::Update->value], $descriptor->operations);
        self::assertEquals($descriptor, ResourceDescriptor::fromArray($descriptor->toArray()));
    }

    public function test_a_read_only_singleton_serves_fetch_alone(): void
    {
        $descriptor = (new DiscoveryScanner())->scan([], [ReadOnlyProfileResource::class])->resources[0];

        self::assertTrue($descriptor->singleton);
        self::assertSame([Operation::FetchOne->value], $descriptor->operations);
    }

    public function test_a_serializer_override_can_declare_the_type_a_singleton(): void
    {
        $descriptor = (new DiscoveryScanner())->scan([], [OverriddenProfileResource::class])->resources[0];

        self::assertTrue($descriptor->singleton);
        self::assertSame([Operation::FetchOne->value, Operation::Update->value], $descriptor->operations);
    }

    public function test_a_standalone_singleton_serializer_serves_fetch_and_update_when_it_has_a_hydrator(): void
    {
        $alone = (new DiscoveryScanner())->scan([], [StandaloneSettingsSerializer::class])->serializers[0];

        self::assertTrue($alone->singleton);
        self::assertSame([Operation::FetchOne->value], $alone->operations);
        self::assertEquals($alone, SerializerDescriptor::fromArray($alone->toArray()));

        $hydrated = (new DiscoveryScanner())->scan([], [StandaloneSettingsSerializer::class, StandaloneSettingsHydrator::class])->serializers[0];
        self::assertSame([Operation::FetchOne->value, Operation::Update->value], $hydrated->operations);
    }

    public function test_a_type_that_is_not_a_singleton_says_so(): void
    {
        $descriptor = (new DiscoveryScanner())->scan([], [\Workbench\App\JsonApi\AlbumResource::class])->resources[0];

        self::assertFalse($descriptor->singleton);
    }

    public function test_a_singleton_declaring_a_collection_fetch_is_refused(): void
    {
        $this->expectException(SingletonMisdeclared::class);
        $this->expectExceptionMessage('Type "listedProfiles" is a singleton, so it serves only fetch and update, but it declares FetchCollection.');

        (new DiscoveryScanner())->scan([], [ListedProfileResource::class]);
    }

    public function test_a_singleton_declaring_a_delete_is_refused(): void
    {
        $this->expectException(SingletonMisdeclared::class);
        $this->expectExceptionMessage('but it declares Delete.');

        (new DiscoveryScanner())->scan([], [DeletableProfileResource::class]);
    }

    public function test_a_collection_action_on_a_singleton_is_refused(): void
    {
        $this->expectException(SingletonMisdeclared::class);
        $this->expectExceptionMessage('declares the collection-scope action "export"');

        (new DiscoveryScanner())->scan([], [ProfileResource::class, ExportProfilesAction::class]);
    }

    public function test_a_singleton_that_cannot_resolve_the_callers_id_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is a singleton, so it must override resolveSingletonId()');

        (new DiscoveryScanner())->scan([], [UnresolvedProfileResource::class]);
    }

    public function test_a_related_only_singleton_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The JSON:API type "relatedOnlyProfiles" is a singleton and related-only');

        (new DiscoveryScanner())->scan([], [RelatedOnlyProfileResource::class]);
    }
}
