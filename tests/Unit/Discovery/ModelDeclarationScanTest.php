<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Discovery;

use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\Discovery\ResourceDescriptor;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\JsonApi\PressingResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\JsonApi\RecordingResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\MissingModelResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\VinylRecord;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\NotAModelResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The scan-time half of the `model:` declaration (ADR 0019): the class-string lands on
 * the {@see ResourceDescriptor} (and survives its snapshot array form, so
 * `jsonapi:optimize` carries it), and a declaration naming a missing or non-Model class
 * fails discovery loudly — the Laravel twin of the bundle `DoctrineEntityMapPass`'s
 * missing-entity guard.
 *
 * @internal
 */
#[CoversClass(DiscoveryScanner::class)]
#[CoversClass(ResourceDescriptor::class)]
#[CoversClass(AsJsonApiResource::class)]
final class ModelDeclarationScanTest extends TestCase
{
    public function test_it_carries_the_declared_model_off_the_attribute(): void
    {
        $result = (new DiscoveryScanner())->scan([], [RecordingResource::class, PressingResource::class]);

        $byType = [];
        foreach ($result->resources as $descriptor) {
            $byType[$descriptor->type] = $descriptor;
        }

        self::assertSame(VinylRecord::class, $byType['recordings']->model);
        // No declaration = a null model; the convention tier resolves it later, at
        // map-resolution time (never at scan time, so the snapshot stays portable).
        self::assertNull($byType['pressings']->model);
    }

    public function test_a_model_naming_a_missing_class_fails_discovery_with_a_clear_message(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'The model "haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\DoesNotExist" declared by '
            . '#[AsJsonApiResource] on "' . MissingModelResource::class . '" must be an Eloquent model class',
        );

        (new DiscoveryScanner())->scan([], [MissingModelResource::class]);
    }

    public function test_a_model_naming_a_non_eloquent_class_fails_discovery(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The model "stdClass" declared by #[AsJsonApiResource]');

        (new DiscoveryScanner())->scan([], [NotAModelResource::class]);
    }

    public function test_a_descriptor_with_a_model_round_trips_through_its_array_form(): void
    {
        $descriptor = new ResourceDescriptor(
            RecordingResource::class,
            'recordings',
            'recordings',
            ['default'],
            [Operation::FetchOne->value],
            model: VinylRecord::class,
        );

        self::assertEquals($descriptor, ResourceDescriptor::fromArray($descriptor->toArray()));

        // A legacy snapshot without the key degrades to no declared model.
        $legacy = $descriptor->toArray();
        unset($legacy['model']);
        self::assertNull(ResourceDescriptor::fromArray($legacy)->model);
    }
}
