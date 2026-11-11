<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\RelatedBatch;

use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see BatchingRelatedProviderTestCase} over runs from the eloquent store.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentBatchingRelatedProviderTest extends BatchingRelatedProviderTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }

    #[Test]
    public function aRelationServedByItsRelatedProviderNeedsNoModelMethod(): void
    {
        $warmer = $this->app?->make(ServableResourceWarmer::class);
        self::assertInstanceOf(ServableResourceWarmer::class, $warmer);

        foreach ($warmer->warm() as $problem) {
            self::assertStringNotContainsString('resolves to method', $problem);
        }
    }
}
