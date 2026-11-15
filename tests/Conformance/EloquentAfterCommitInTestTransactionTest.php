<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\Event\BeforeSaveEvent;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\TxEloquentServiceProvider;
use Workbench\App\WriteTransaction\TxScenario;

/**
 * A test using Laravel's `DatabaseTransactions` wraps itself in a transaction the package
 * would otherwise wait for. Laravel's test transaction manager ignores that transaction, so
 * after-commit callbacks still run when the write commits.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentAfterCommitInTestTransactionTest extends Orchestra
{
    use DatabaseTransactions;
    use InteractsWithJsonApi;

    protected function setUp(): void
    {
        parent::setUp();
        TxScenario::reset();
    }

    protected function tearDown(): void
    {
        TxScenario::reset();
        parent::tearDown();
    }

    #[Test]
    public function aWriteInsideTheTestsTransactionRunsItsCallbacksWhenItCommits(): void
    {
        TxScenario::$afterCommitOn = BeforeSaveEvent::class;

        $this->jsonApi()
            ->withDocument(['data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'third']]])
            ->post('/api/txWidgets')
            ->assertStatus(201);

        self::assertSame(1, DB::connection()->transactionLevel());
        self::assertSame(['callback from BeforeSaveEvent', 'AfterCreateEvent', 'AfterSaveEvent'], TxScenario::$log);
        self::assertSame([1], TxScenario::$callbackLevels);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, TxEloquentServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
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
}
