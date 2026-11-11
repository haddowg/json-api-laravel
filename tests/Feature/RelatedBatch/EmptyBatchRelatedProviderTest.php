<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\RelatedBatch;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * The related types' providers keep the base class's empty batch, so every run is read on its
 * own, as before.
 *
 * @internal
 */
#[CoversNothing]
final class EmptyBatchRelatedProviderTest extends RelatedBatchTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }

    protected function batches(): bool
    {
        return false;
    }

    #[Test]
    public function aToManyIsReadPerRunAfterAnEmptyBatch(): void
    {
        $runs = $this->read('/api/runs?include=advertisers');

        $runs->assertJsonPath('data.0.relationships.advertisers.data', [['type' => 'advertisers', 'id' => '1'], ['type' => 'advertisers', 'id' => '2']]);
        $runs->assertJsonPath('data.1.relationships.advertisers.data', [['type' => 'advertisers', 'id' => '3']]);
        $runs->assertJsonPath('data.2.relationships.advertisers.data', []);
        self::assertSame(['advertisers:1', 'advertisers:2', 'advertisers:3'], $this->included($runs));
        self::assertSame(1, $this->advertisers()->batchCalls);
        self::assertSame(3, $this->advertisers()->relatedCalls);
    }

    #[Test]
    public function aToOneTheBatchLeavesOutIsReadOffTheRun(): void
    {
        $runs = $this->read('/api/runs?include=progress');

        // The run has no relation to read, so the progress renders as none.
        $runs->assertJsonPath('data.0.relationships.progress.data', null);
        self::assertSame([], $this->included($runs));
        self::assertSame(1, $this->progresses()->batchCalls);
        self::assertSame(0, $this->progresses()->relatedCalls);
    }
}
