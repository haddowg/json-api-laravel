<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataPersister;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataPersister\AfterCommit;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface;
use haddowg\JsonApiLaravel\DataPersister\TransactionalDataPersisterInterface;
use haddowg\JsonApiLaravel\DataPersister\WriteTransaction;
use haddowg\JsonApiLaravel\DataPersister\WriteTransactionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The after-commit scopes {@see WriteTransaction} opens: a callback runs once the outermost
 * write commits, a rollback drops the callbacks of its own scope only, and outside any write
 * a callback runs at once.
 *
 * @internal
 */
#[CoversClass(AfterCommit::class)]
#[CoversClass(WriteTransaction::class)]
final class AfterCommitTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];

    #[Test]
    public function aCallbackAddedOutsideAWriteRunsAtOnce(): void
    {
        $afterCommit = new AfterCommit();

        $afterCommit->register($this->record('now'));

        self::assertSame(['now'], $this->log);
    }

    #[Test]
    public function callbacksRunAfterTheCommitInTheOrderTheyWereAdded(): void
    {
        $afterCommit = new AfterCommit();
        $persister = $this->persister();

        WriteTransaction::run($persister, null, function () use ($afterCommit): void {
            $afterCommit->register($this->record('first'));
            $afterCommit->register($this->record('second'));
            $this->log[] = 'write';
        }, $afterCommit);

        self::assertSame(['begin', 'write', 'commit', 'first', 'second'], $this->log);
    }

    #[Test]
    public function aWriteThatThrowsDropsItsCallbacks(): void
    {
        $afterCommit = new AfterCommit();
        $persister = $this->persister();

        try {
            WriteTransaction::run($persister, null, function () use ($afterCommit): void {
                $afterCommit->register($this->record('dropped'));

                throw new \RuntimeException('boom');
            }, $afterCommit);
        } catch (\RuntimeException) {
        }

        self::assertSame(['begin', 'rollback'], $this->log);

        $afterCommit->register($this->record('later'));
        self::assertSame(['begin', 'rollback', 'later'], $this->log);
    }

    #[Test]
    public function aWriteWithNoTransactionRunsItsCallbacksOnceItReturns(): void
    {
        $afterCommit = new AfterCommit();

        WriteTransaction::run(null, null, function () use ($afterCommit): void {
            $afterCommit->register($this->record('callback'));
            $this->log[] = 'write';
        }, $afterCommit);

        self::assertSame(['write', 'callback'], $this->log);
    }

    #[Test]
    public function aWriteInsideABatchRunsItsCallbacksOnlyWhenTheBatchCommits(): void
    {
        $afterCommit = new AfterCommit();
        $context = new WriteTransactionContext();
        $context->activate();
        $persister = $this->persister();

        $afterCommit->open();
        WriteTransaction::run($persister, $context, function () use ($afterCommit): void {
            $afterCommit->register($this->record('callback'));
        }, $afterCommit);
        self::assertSame([], $this->log);

        $afterCommit->commit();
        self::assertSame(['callback'], $this->log);
    }

    #[Test]
    public function onlyTheOutermostCommitRunsTheCallbacks(): void
    {
        $afterCommit = new AfterCommit();
        $persister = $this->persister();

        WriteTransaction::run($persister, null, function () use ($afterCommit, $persister): void {
            $afterCommit->register($this->record('outer'));
            WriteTransaction::run($persister, null, function () use ($afterCommit): void {
                $afterCommit->register($this->record('inner'));
            }, $afterCommit);
            $this->log[] = 'inner returned';
        }, $afterCommit);

        self::assertSame(['begin', 'begin', 'commit', 'inner returned', 'commit', 'outer', 'inner'], $this->log);
    }

    #[Test]
    public function anInnerRollbackDropsOnlyItsOwnCallbacks(): void
    {
        $afterCommit = new AfterCommit();

        WriteTransaction::run(null, null, function () use ($afterCommit): void {
            $afterCommit->register($this->record('outer'));
            try {
                WriteTransaction::run(null, null, function () use ($afterCommit): void {
                    $afterCommit->register($this->record('inner'));

                    throw new \RuntimeException('boom');
                }, $afterCommit);
            } catch (\RuntimeException) {
            }
        }, $afterCommit);

        self::assertSame(['outer'], $this->log);
    }

    #[Test]
    public function anOuterRollbackDropsTheCallbacksOfTheWritesItHolds(): void
    {
        $afterCommit = new AfterCommit();

        try {
            WriteTransaction::run(null, null, function () use ($afterCommit): void {
                WriteTransaction::run(null, null, function () use ($afterCommit): void {
                    $afterCommit->register($this->record('inner'));
                }, $afterCommit);

                throw new \RuntimeException('boom');
            }, $afterCommit);
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->log);
    }

    #[Test]
    public function aCallbackThatThrowsIsLoggedAndTheRestStillRun(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $afterCommit = new AfterCommit($logger);
        $thrown = new \RuntimeException('boom');

        WriteTransaction::run(null, null, function () use ($afterCommit, $thrown): void {
            $afterCommit->register(static function () use ($thrown): never {
                throw $thrown;
            });
            $afterCommit->register($this->record('second'));
        }, $afterCommit);

        self::assertSame(['second'], $this->log);
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0][0]);
        self::assertSame($thrown, $logger->records[0][2]['exception'] ?? null);
    }

    #[Test]
    public function aCallbackAddedByACallbackRunsAtOnce(): void
    {
        $afterCommit = new AfterCommit();

        WriteTransaction::run(null, null, function () use ($afterCommit): void {
            $afterCommit->register(function () use ($afterCommit): void {
                $this->log[] = 'first';
                $afterCommit->register($this->record('nested'));
                $this->log[] = 'first done';
            });
        }, $afterCommit);

        self::assertSame(['first', 'nested', 'first done'], $this->log);
    }

    #[Test]
    public function resetDropsEveryOpenScope(): void
    {
        $afterCommit = new AfterCommit();
        $afterCommit->open();
        $afterCommit->register($this->record('leaked'));

        $afterCommit->reset();
        $afterCommit->register($this->record('now'));

        self::assertSame(['now'], $this->log);
    }

    /**
     * @return \Closure(): void
     */
    private function record(string $entry): \Closure
    {
        return function () use ($entry): void {
            $this->log[] = $entry;
        };
    }

    private function persister(): DataPersisterInterface&TransactionalDataPersisterInterface
    {
        return new class (function (string $entry): void {
            $this->log[] = $entry;
        }) implements DataPersisterInterface, TransactionalDataPersisterInterface {
            /**
             * @param \Closure(string): void $log
             */
            public function __construct(private readonly \Closure $log) {}

            public function supports(string $type): bool
            {
                return true;
            }

            public function instantiate(string $type): object
            {
                return new \stdClass();
            }

            public function create(string $type, object $entity): object
            {
                return $entity;
            }

            public function update(string $type, object $entity): object
            {
                return $entity;
            }

            public function delete(string $type, object $entity): void {}

            public function mutateRelationship(string $type, object $entity, RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, Mode $mode, bool $flush = true): object
            {
                return $entity;
            }

            public function beginTransaction(): void
            {
                ($this->log)('begin');
            }

            public function commit(): void
            {
                ($this->log)('commit');
            }

            public function rollback(): void
            {
                ($this->log)('rollback');
            }
        };
    }
}
