<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Eloquent;

use haddowg\JsonApiLaravel\Discovery\ResourceDescriptor;
use haddowg\JsonApiLaravel\Eloquent\ModelMapResolver;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\JsonApi\PressingResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\JsonApi\RecordingResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\Pressing;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\VinylRecord;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Unservable\GhostResource;
use haddowg\JsonApiLaravel\Tests\Support\InflectionSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The `type → model` resolution behind the auto-registered reference Eloquent pair
 * (ADR 0002): the declared `model:` tier wins over the convention guess, the convention
 * guess claims only an existing Eloquent model under the configured namespace, an
 * unresolvable type is simply absent (no claim), and one type declared against two
 * different models is a loud wiring fault — the bundle `DoctrineEntityMapPass`'s
 * duplicate guard.
 *
 * @internal
 */
#[CoversClass(ModelMapResolver::class)]
final class ModelMapResolverTest extends TestCase
{
    private const string MODEL_NAMESPACE = 'haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models';

    public function test_a_declared_model_maps_its_type(): void
    {
        $resolver = new ModelMapResolver(
            [$this->descriptor(RecordingResource::class, 'recordings', model: VinylRecord::class)],
            self::MODEL_NAMESPACE,
        );

        self::assertSame(['recordings' => VinylRecord::class], $resolver->map());
    }

    public function test_the_convention_guess_resolves_a_type_under_the_configured_namespace(): void
    {
        $resolver = new ModelMapResolver(
            [$this->descriptor(PressingResource::class, 'pressings')],
            self::MODEL_NAMESPACE,
        );

        self::assertSame(['pressings' => Pressing::class], $resolver->map());
    }

    public function test_the_convention_guess_studly_singularizes_a_kebab_plural_type(): void
    {
        // 'vinyl-records' → singular 'vinyl-record' → studly 'VinylRecord'.
        $resolver = new ModelMapResolver(
            [$this->descriptor(RecordingResource::class, 'vinyl-records')],
            self::MODEL_NAMESPACE,
        );

        self::assertSame(['vinyl-records' => VinylRecord::class], $resolver->map());
    }

    public function test_a_declared_model_wins_over_a_convention_match_for_the_same_type(): void
    {
        // 'pressings' has a convention match (Pressing) — the declaration still wins.
        $resolver = new ModelMapResolver(
            [$this->descriptor(PressingResource::class, 'pressings', model: VinylRecord::class)],
            self::MODEL_NAMESPACE,
        );

        self::assertSame(['pressings' => VinylRecord::class], $resolver->map());
    }

    public function test_a_type_with_no_declaration_and_no_convention_match_is_not_claimed(): void
    {
        $resolver = new ModelMapResolver(
            [$this->descriptor(GhostResource::class, 'ghosts')],
            self::MODEL_NAMESPACE,
        );

        self::assertSame([], $resolver->map());
    }

    public function test_a_convention_match_that_is_not_an_eloquent_model_is_not_claimed(): void
    {
        // 'import-entries' guesses ImportEntry — an existing class, but a POPO, so the
        // convention tier must not claim it.
        $resolver = new ModelMapResolver(
            [$this->descriptor(GhostResource::class, 'import-entries')],
            'haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap',
        );

        self::assertSame([], $resolver->map());
    }

    public function test_a_null_namespace_disables_the_convention_tier_but_not_the_declared_tier(): void
    {
        $resolver = new ModelMapResolver(
            [
                $this->descriptor(PressingResource::class, 'pressings'),
                $this->descriptor(RecordingResource::class, 'recordings', model: VinylRecord::class),
            ],
            null,
        );

        self::assertSame(['recordings' => VinylRecord::class], $resolver->map());
    }

    public function test_two_resources_may_declare_the_same_model_for_different_types(): void
    {
        // The "two types, one model" pattern (users + public-profiles) is legal.
        $resolver = new ModelMapResolver(
            [
                $this->descriptor(RecordingResource::class, 'recordings', model: VinylRecord::class),
                $this->descriptor(GhostResource::class, 'archived-recordings', model: VinylRecord::class),
            ],
            null,
        );

        self::assertSame(
            ['recordings' => VinylRecord::class, 'archived-recordings' => VinylRecord::class],
            $resolver->map(),
        );
    }

    public function test_one_type_declared_against_two_different_models_is_a_wiring_fault(): void
    {
        $resolver = new ModelMapResolver(
            [
                $this->descriptor(RecordingResource::class, 'recordings', model: VinylRecord::class),
                $this->descriptor(GhostResource::class, 'recordings', model: Pressing::class),
            ],
            null,
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('JSON:API type "recordings" is mapped to two different Eloquent models');

        $resolver->map();
    }

    public function test_the_live_resolution_inflects_each_convention_type(): void
    {
        InflectionSpy::install();

        try {
            $resolver = new ModelMapResolver(
                [$this->descriptor(PressingResource::class, 'pressings')],
                self::MODEL_NAMESPACE,
            );

            self::assertSame(['pressings' => Pressing::class], $resolver->map());
            self::assertSame(1, InflectionSpy::$singularized);
        } finally {
            InflectionSpy::uninstall();
        }
    }

    public function test_a_map_resolved_ahead_of_time_is_returned_without_inflecting(): void
    {
        InflectionSpy::install();

        try {
            $resolver = new ModelMapResolver(
                [$this->descriptor(PressingResource::class, 'pressings')],
                self::MODEL_NAMESPACE,
                ['pressings' => Pressing::class],
            );

            self::assertSame(['pressings' => Pressing::class], $resolver->map());
            self::assertSame(0, InflectionSpy::$singularized);
        } finally {
            InflectionSpy::uninstall();
        }
    }

    public function test_an_empty_map_resolved_ahead_of_time_is_kept_as_empty(): void
    {
        $resolver = new ModelMapResolver(
            [$this->descriptor(PressingResource::class, 'pressings')],
            self::MODEL_NAMESPACE,
            [],
        );

        self::assertSame([], $resolver->map());
    }

    public function test_resolve_ignores_a_map_resolved_ahead_of_time_and_still_rejects_a_conflict(): void
    {
        // The snapshot writer calls resolve(), so the conflict check runs at optimize time
        // even when the app it runs in loaded an earlier snapshot's map.
        $resolver = new ModelMapResolver(
            [
                $this->descriptor(RecordingResource::class, 'recordings', model: VinylRecord::class),
                $this->descriptor(GhostResource::class, 'recordings', model: Pressing::class),
            ],
            null,
            ['recordings' => VinylRecord::class],
        );

        self::assertSame(['recordings' => VinylRecord::class], $resolver->map());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('JSON:API type "recordings" is mapped to two different Eloquent models');

        $resolver->resolve();
    }

    /**
     * @param class-string<\haddowg\JsonApi\Resource\AbstractResource> $class
     * @param class-string<\Illuminate\Database\Eloquent\Model>|null   $model
     */
    private function descriptor(string $class, string $type, ?string $model = null): ResourceDescriptor
    {
        return new ResourceDescriptor(
            $class,
            $type,
            $type,
            ['default'],
            [Operation::FetchCollection->value],
            model: $model,
        );
    }
}
