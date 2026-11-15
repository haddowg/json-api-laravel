<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\Event\BeforeCreateEvent;
use haddowg\JsonApiLaravel\Event\BeforeSaveEvent;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TxNote;
use Workbench\App\Models\TxWidget;
use Workbench\App\Providers\TxEloquentServiceProvider;
use Workbench\App\WriteTransaction\TxScenario;

/**
 * {@see WriteTransactionConformanceTestCase} on Eloquent over SQLite, plus what only a
 * database connection can show: the level a listener writes at, and that the connection is
 * out of every transaction once a write has ended.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentWriteTransactionConformanceTest extends WriteTransactionConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return TxEloquentServiceProvider::class;
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
        $note = TxNote::query()->create(['body' => 'seeded']);
        TxWidget::query()->create(['name' => 'first'])->notes()->attach($note->id);
        TxWidget::query()->create(['name' => 'second']);
    }

    #[Test]
    public function aBeforeListenerRunsInsideTheWritesTransaction(): void
    {
        TxScenario::$sideWriteOn = BeforeCreateEvent::class;

        $this->jsonApi()->withDocument($this->widget('third'))->post('/api/txWidgets')->assertStatus(201);

        self::assertSame(1, TxScenario::$levelSeen);
    }

    #[Test]
    public function anAtomicOperationRunsInTheBatchTransactionAlone(): void
    {
        TxScenario::$sideWriteOn = BeforeCreateEvent::class;

        $this->atomic([['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'third']]]])->assertStatus(200);

        self::assertSame(1, TxScenario::$levelSeen);
    }

    #[Test]
    public function aFailedSaveLeavesNoTransactionOpen(): void
    {
        TxScenario::$sideWriteOn = BeforeCreateEvent::class;

        // The name is unique, so the create's own insert fails after the listener's has run.
        $this->jsonApi()->withDocument($this->widget('first'))->post('/api/txWidgets')->assertStatus(500);

        $this->assertNoTransactionOpen();
        TxScenario::reset();
        self::assertSame(['seeded'], $this->notes());
        $this->jsonApi()->withDocument($this->widget('third'))->post('/api/txWidgets')->assertStatus(201);
    }

    #[Test]
    public function anUpdateAcceptedForProcessingLeavesTheResourceAsStored(): void
    {
        TxScenario::$accept = true;

        $this->jsonApi()->withDocument($this->widget('renamed', id: '1'))->patch('/api/txWidgets/1')->assertStatus(202);

        TxScenario::reset();
        self::assertSame(['first', 'second'], $this->widgetNames());
    }

    #[Test]
    public function callbacksRunOnceTheConnectionHasCommitted(): void
    {
        TxScenario::$afterCommitOn = BeforeCreateEvent::class;
        TxScenario::$actionAfterCommit = true;

        $this->jsonApi()->withDocument($this->widget('third'))->post('/api/txWidgets')->assertStatus(201);
        $this->jsonApi()->withHeader('Content-Type', 'application/vnd.api+json')->post('/api/txWidgets/1/-actions/stamp')->assertStatus(204);
        $this->atomic([['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'fourth']]]])->assertStatus(200);

        self::assertSame([0, 0, 0], TxScenario::$callbackLevels);
    }

    #[Test]
    public function callbacksWaitForTheApplicationsOwnTransactionToCommit(): void
    {
        TxScenario::$afterCommitOn = BeforeSaveEvent::class;
        TxScenario::$actionAfterCommit = true;
        TxScenario::$callbackThrows = true;

        DB::transaction(function (): void {
            $this->jsonApi()->withDocument($this->widget('third'))->post('/api/txWidgets')->assertStatus(201);
            $this->jsonApi()->withHeader('Content-Type', 'application/vnd.api+json')->post('/api/txWidgets/1/-actions/stamp')->assertStatus(204);

            self::assertSame(['AfterCreateEvent', 'AfterSaveEvent', 'AfterActionEvent'], TxScenario::$log);
            self::assertSame(1, DB::connection()->transactionLevel());
        });

        self::assertSame(['AfterCreateEvent', 'AfterSaveEvent', 'AfterActionEvent', 'callback from BeforeSaveEvent', 'callback from stamp'], TxScenario::$log);
        self::assertSame([0, 0], TxScenario::$callbackLevels);
    }

    #[Test]
    public function callbacksAreDroppedWhenTheApplicationsOwnTransactionRollsBack(): void
    {
        TxScenario::$afterCommitOn = BeforeSaveEvent::class;

        DB::beginTransaction();
        $this->jsonApi()->withDocument($this->widget('third'))->post('/api/txWidgets')->assertStatus(201);
        $this->atomic([['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'fourth']]]])->assertStatus(200);
        DB::rollBack();

        self::assertSame(['AfterCreateEvent', 'AfterSaveEvent', 'AfterCreateEvent', 'AfterSaveEvent'], TxScenario::$log);
        self::assertSame(['first', 'second'], $this->widgetNames());
    }

    #[Test]
    public function anAtomicBatchsCallbacksWaitForTheApplicationsOwnTransaction(): void
    {
        TxScenario::$afterCommitOn = BeforeSaveEvent::class;

        DB::transaction(function (): void {
            $this->atomic([['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'third']]]])->assertStatus(200);

            self::assertSame(['AfterCreateEvent', 'AfterSaveEvent'], TxScenario::$log);
        });

        self::assertSame(['AfterCreateEvent', 'AfterSaveEvent', 'callback from BeforeSaveEvent'], TxScenario::$log);
    }

    protected function assertNoTransactionOpen(): void
    {
        self::assertSame(0, DB::connection()->transactionLevel());
    }
}
