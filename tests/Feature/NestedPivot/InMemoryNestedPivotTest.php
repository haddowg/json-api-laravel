<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\NestedPivot;

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
}
