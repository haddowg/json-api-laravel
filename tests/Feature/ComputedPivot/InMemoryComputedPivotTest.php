<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ComputedPivot;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ComputedPivot\ComputedPivotInMemoryServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\ConformanceInMemoryServiceProvider;

/**
 * {@see ComputedPivotTestCase} on the in-memory witness. It stores no pivot row, so a relation
 * mixing stored and computed fields renders only its computed ones there, never a stored one,
 * even a stored one read through an extract hook.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryComputedPivotTest extends ComputedPivotTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            ConformanceInMemoryServiceProvider::class,
            ComputedPivotInMemoryServiceProvider::class,
        ];
    }

    public function test_a_mixed_relation_renders_only_its_computed_field(): void
    {
        foreach (['/api/crates/1/mixedTracks', '/api/crates/1/relationships/mixedTracks'] as $uri) {
            $response = $this->read($uri)->assertOk();

            // Neither the stored position nor the stored, hook-read weight renders.
            self::assertSame(['label' => 'Best Of / Karma Police'], $this->pivotOf($response, '3'), $uri);
        }
    }
}
