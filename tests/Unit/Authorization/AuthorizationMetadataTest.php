<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Authorization;

use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Authorization\ResourceAuthorization;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\Discovery\ResourceDescriptor;
use haddowg\JsonApiLaravel\Operation\Operation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Workbench\App\Security\AlbumResource;
use Workbench\App\Security\GenreResource;
use Workbench\App\Security\Policies\AlbumApiPolicy;

/**
 * The authorization metadata plumbing (PLAN decision 7): the `policy`/`abilities`
 * overrides declared on {@see AsJsonApiResource} flow through discovery onto the
 * {@see ResourceDescriptor} (and its cache round-trip) so the
 * {@see \haddowg\JsonApiLaravel\Authorization\Authorizer} can read them per type.
 *
 * @internal
 */
#[CoversClass(AsJsonApiResource::class)]
#[CoversClass(ResourceDescriptor::class)]
#[CoversClass(ResourceAuthorization::class)]
#[CoversClass(DiscoveryScanner::class)]
final class AuthorizationMetadataTest extends TestCase
{
    public function test_the_attribute_defaults_to_no_policy_and_no_ability_overrides(): void
    {
        $attribute = new AsJsonApiResource();

        self::assertNull($attribute->policy);
        self::assertSame([], $attribute->abilities);
    }

    public function test_the_attribute_carries_a_policy_and_ability_overrides(): void
    {
        $attribute = new AsJsonApiResource(
            policy: AlbumApiPolicy::class,
            abilities: [Operation::Create->value => 'publish', Operation::Delete->value => false],
        );

        self::assertSame(AlbumApiPolicy::class, $attribute->policy);
        self::assertSame(['Create' => 'publish', 'Delete' => false], $attribute->abilities);
    }

    public function test_discovery_surfaces_the_policy_and_abilities_on_the_descriptor(): void
    {
        $result = (new DiscoveryScanner())->scan([], [AlbumResource::class]);

        self::assertCount(1, $result->resources);
        $descriptor = $result->resources[0];

        self::assertSame(AlbumApiPolicy::class, $descriptor->policy);
        self::assertSame(['Create' => 'publish', 'Delete' => false], $descriptor->abilities);
    }

    public function test_a_policy_less_resource_has_the_inert_authorization_default(): void
    {
        $result = (new DiscoveryScanner())->scan([], [GenreResource::class]);

        $descriptor = $result->resources[0];

        self::assertNull($descriptor->policy);
        self::assertSame([], $descriptor->abilities);
    }

    public function test_the_descriptor_round_trips_its_authorization_metadata(): void
    {
        $descriptor = new ResourceDescriptor(
            AlbumResource::class,
            'albums',
            'albums',
            ['secure'],
            [Operation::Create->value],
            AlbumApiPolicy::class,
            ['Create' => 'publish', 'Delete' => false],
        );

        self::assertEquals($descriptor, ResourceDescriptor::fromArray($descriptor->toArray()));
    }

    public function test_from_array_tolerates_a_legacy_snapshot_without_authorization_keys(): void
    {
        // A pre-decision-7 discovery cache lacks the policy/abilities keys; it must
        // degrade to the inert default rather than erroring.
        $descriptor = ResourceDescriptor::fromArray([
            'class' => AlbumResource::class,
            'type' => 'albums',
            'uriType' => 'albums',
            'servers' => ['secure'],
            'operations' => [Operation::Create->value],
        ]);

        self::assertNull($descriptor->policy);
        self::assertSame([], $descriptor->abilities);
    }

    public function test_resource_authorization_resolves_the_per_operation_override(): void
    {
        $authorization = new ResourceAuthorization(
            AlbumApiPolicy::class,
            [Operation::Create->value => 'publish', Operation::Delete->value => false],
        );

        // A rename returns the new ability name, a disable returns false, and an operation
        // with no override returns null (the caller falls back to the convention default).
        self::assertSame('publish', $authorization->ability(Operation::Create));
        self::assertFalse($authorization->ability(Operation::Delete));
        self::assertNull($authorization->ability(Operation::Update));
    }
}
