<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\RelatedBatch;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see BatchingRelatedProviderTestCase} over runs from the memory store.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryBatchingRelatedProviderTest extends BatchingRelatedProviderTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
