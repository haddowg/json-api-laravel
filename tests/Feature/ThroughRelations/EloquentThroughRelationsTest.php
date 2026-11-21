<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ThroughRelations;

use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations\ThroughRelationsServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see ThroughRelationsTestCase} over Eloquent, where each hop is a `whereHas` or a correlated
 * subselect and the advertisers' global scope hides Shadow.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentThroughRelationsTest extends ThroughRelationsTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineDatabaseMigrations(): void
    {
        ThroughRelationsServiceProvider::migrate();
    }
}
