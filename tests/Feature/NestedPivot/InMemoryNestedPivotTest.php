<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\NestedPivot;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see NestedPivotTestCase} on the in-memory provider, which stores no pivot columns and so
 * renders only the computed field.
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
        return false;
    }
}
