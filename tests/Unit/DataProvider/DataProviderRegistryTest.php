<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider;

use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DataProviderRegistry::class)]
final class DataProviderRegistryTest extends TestCase
{
    #[Test]
    public function itReturnsTheProviderSupportingTheType(): void
    {
        $articles = new InMemoryDataProvider('articles', []);
        $comments = new InMemoryDataProvider('comments', []);

        $registry = new DataProviderRegistry([$articles, $comments]);

        self::assertSame($comments, $registry->forType('comments'));
        self::assertSame($articles, $registry->forType('articles'));
    }

    #[Test]
    public function itReturnsTheFirstMatchingProviderWhenSeveralSupportTheType(): void
    {
        // Providers are injected in descending priority (highest first); the first to
        // `supports()` the type wins, so an application provider shadows the reference one.
        $high = new InMemoryDataProvider('articles', []);
        $low = new InMemoryDataProvider('articles', []);

        $registry = new DataProviderRegistry([$high, $low]);

        self::assertSame($high, $registry->forType('articles'));
    }

    #[Test]
    public function aTypeWithNoProviderIsAWiringError(): void
    {
        $registry = new DataProviderRegistry([new InMemoryDataProvider('articles', [])]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No JSON:API data provider is registered for type "comments".');

        $registry->forType('comments');
    }

    #[Test]
    public function itReportsWhetherATypeIsSupportedWithoutThrowing(): void
    {
        $registry = new DataProviderRegistry([new InMemoryDataProvider('articles', [])]);

        self::assertTrue($registry->supportsType('articles'));
        self::assertFalse($registry->supportsType('comments'));
    }

    #[Test]
    public function itAcceptsProvidersFromAnyIterable(): void
    {
        $articles = new InMemoryDataProvider('articles', []);

        $registry = new DataProviderRegistry((static function () use ($articles): \Generator {
            yield $articles;
        })());

        self::assertSame($articles, $registry->forType('articles'));
    }
}
