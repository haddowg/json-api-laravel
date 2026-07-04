<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\Article;
use Workbench\App\Providers\ValidationEloquentServiceProvider;

/**
 * {@see ValidationConformanceTestCase} against the **reference Eloquent provider/persister
 * pair** executed as real SQL over an in-memory SQLite database — so the bridge's 422/200
 * behaviour is refereed identically to the in-memory witness, and the pre-hydration
 * `Rule::unique` (UniqueEntity) actually queries the table (the merge-before-validate and
 * self-ignore paths exercised on real storage).
 *
 * @internal
 */
#[CoversNothing]
final class EloquentValidationConformanceTest extends ValidationConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return ValidationEloquentServiceProvider::class;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\dirname(__DIR__, 2) . '/workbench/database/migrations');
    }

    protected function seedConformanceData(): void
    {
        Article::query()->create(['id' => 1, 'title' => 'JSON:API in PHP', 'category' => 'guide', 'slug' => 'json-api-in-php']);
        Article::query()->create(['id' => 2, 'title' => 'Second Article', 'category' => 'news', 'slug' => 'second-article']);
    }
}
