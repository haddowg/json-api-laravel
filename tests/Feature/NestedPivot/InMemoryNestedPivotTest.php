<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\NestedPivot;

use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\InMemoryStore;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see NestedPivotTestCase} on the in-memory provider, which keeps the pivot rows with the
 * parents and renders them beside the computed field.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryNestedPivotTest extends NestedPivotTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('nested_pivot.store', 'memory');

        return parent::getPackageProviders($app);
    }

    protected function storesPivots(): bool
    {
        return true;
    }

    public function test_a_member_with_no_pivot_row_fails_the_render_as_a_data_integrity_error(): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->app?->make('config');
        $config->set('app.debug', true);
        $registry = $this->app?->make(DataProviderRegistry::class);
        self::assertInstanceOf(DataProviderRegistry::class, $registry);
        $crate = $registry->forType('npCrates')->fetchOne('npCrates', '1');
        self::assertNotNull($crate);
        $store = InMemoryStore::holding($crate);
        self::assertNotNull($store);
        // Record 2 stays in crate 1, with no pivot row.
        $store->writePivotRows($crate, 'records', \array_diff_key($store->pivotRows($crate, 'records'), ['2' => true]));

        $response = $this->get('/api/npCrates/1?include=records', ['Accept' => 'application/vnd.api+json']);

        self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString(
            'The \\"records\\" relation of \\"npCrates\\" \\"1\\" renders the member \\"2\\", but ',
            (string) $response->getContent(),
        );
    }
}
