<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataPersister;

use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryStore;
use haddowg\JsonApiLaravel\Tests\Fixtures\Widget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DataPersisterRegistry::class)]
final class DataPersisterRegistryTest extends TestCase
{
    #[Test]
    public function itReturnsThePersisterSupportingTheType(): void
    {
        $articles = $this->persister('articles');
        $comments = $this->persister('comments');

        $registry = new DataPersisterRegistry([$articles, $comments]);

        self::assertSame($comments, $registry->forType('comments'));
        self::assertSame($articles, $registry->forType('articles'));
    }

    #[Test]
    public function itReturnsTheFirstMatchingPersisterWhenSeveralSupportTheType(): void
    {
        $high = $this->persister('articles');
        $low = $this->persister('articles');

        $registry = new DataPersisterRegistry([$high, $low]);

        self::assertSame($high, $registry->forType('articles'));
    }

    #[Test]
    public function aTypeWithNoPersisterIsAWiringError(): void
    {
        $registry = new DataPersisterRegistry([$this->persister('articles')]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No JSON:API data persister is registered for type "comments".');

        $registry->forType('comments');
    }

    #[Test]
    public function itReportsWhetherATypeIsSupportedWithoutThrowing(): void
    {
        $registry = new DataPersisterRegistry([$this->persister('articles')]);

        self::assertTrue($registry->supportsType('articles'));
        self::assertFalse($registry->supportsType('comments'));
    }

    private function persister(string $type): InMemoryDataPersister
    {
        return new InMemoryDataPersister($type, new InMemoryStore(), static fn(): Widget => new Widget(0, ''));
    }
}
