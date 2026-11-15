<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\DataPersister\WriteTransactionContext;
use haddowg\JsonApiLaravel\Event\BeforeCreateEvent;
use haddowg\JsonApiLaravel\Event\BeforeSaveEvent;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\TxInMemoryServiceProvider;
use Workbench\App\WriteTransaction\TxScenario;

/**
 * {@see WriteTransactionConformanceTestCase} on the in-memory witness, whose transaction is
 * a snapshot of both stores taken through one coordinator.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryWriteTransactionConformanceTest extends WriteTransactionConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return TxInMemoryServiceProvider::class;
    }

    #[Test]
    public function aBatchContextLeftActiveIsResetByTheNextDispatch(): void
    {
        // A batch that never deactivated the singleton context, in a long-lived worker.
        $context = app(WriteTransactionContext::class);
        $context->activate();

        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$throwOn = BeforeCreateEvent::class;
        $this->jsonApi()->withDocument($this->widget('third'))->post('/api/txWidgets')->assertStatus(403);

        self::assertFalse($context->isActive());
        self::assertSame(['seeded'], $this->notes());
    }
}
