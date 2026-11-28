<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ComputedPivot;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ComputedPivot\ComputedPivotInMemoryServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\ConformanceInMemoryServiceProvider;

/**
 * {@see ComputedPivotTestCase} on the in-memory witness, which keeps the pivot rows beside each
 * crate, so a relation mixing stored and computed fields renders both, a stored one read through
 * an extract hook included, as the Eloquent provider does.
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

    public function test_a_stored_and_a_computed_pivot_field_render_side_by_side(): void
    {
        foreach (['/api/crates/1/mixedTracks', '/api/crates/1/relationships/mixedTracks'] as $uri) {
            $response = $this->read($uri)->assertOk();

            self::assertSame(['position' => 2, 'label' => 'Best Of / Karma Police', 'weight' => 300], $this->pivotOf($response, '3'), $uri);
        }
    }
}
