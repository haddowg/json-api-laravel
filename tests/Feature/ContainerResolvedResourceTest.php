<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ContainerResourceServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\FixedClock;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Verifies blueprint risk item 2 / PLAN decision 3: a resource with a real constructor
 * dependency is constructed through the application container on first use, not
 * plain-`new`ed. {@see \haddowg\JsonApiLaravel\Tests\Fixtures\ClockStampResource} takes an
 * interface-typed {@see \haddowg\JsonApiLaravel\Tests\Fixtures\Clock}; its `stamp`
 * attribute is computed off that injection. If core resolved the resource with a plain
 * `new`, construction would fail on the unsatisfiable interface parameter — so the
 * endpoint rendering the injected label at all proves the lazy registry resolves through
 * `app()->make()`.
 *
 * @internal
 */
#[CoversNothing]
final class ContainerResolvedResourceTest extends TestCase
{
    public function test_a_resource_with_a_constructor_dependency_is_container_resolved(): void
    {
        $response = $this->get('/api/clock-stamps/1', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertJsonPath('data.type', 'clock-stamps');
        $response->assertJsonPath('data.id', '1');
        $response->assertJsonPath('data.attributes.stamp', FixedClock::LABEL);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            ContainerResourceServiceProvider::class,
        ];
    }
}
