<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\RelatedBatch;

use haddowg\JsonApiLaravel\DataProvider\RelatedIncludeBatcher;
use PHPUnit\Framework\Attributes\Test;

/**
 * A run's `progress` (a to-one) and `advertisers` (a to-many) are served by their own types'
 * providers, which answer a whole page of runs in one batch. The run has no relation method for
 * either, so the batch is where each one's members come from, on every read path.
 *
 * @internal
 */
abstract class BatchingRelatedProviderTestCase extends RelatedBatchTestCase
{
    #[Test]
    public function aPageRendersLinkageFromOneBatchPerRelation(): void
    {
        $runs = $this->read('/api/runs');

        $runs->assertJsonPath('data.0.relationships.progress.data', ['type' => 'progresses', 'id' => 'p1']);
        $runs->assertJsonPath('data.1.relationships.progress.data', ['type' => 'progresses', 'id' => 'p2']);
        $runs->assertJsonPath('data.2.relationships.progress.data', null);
        $runs->assertJsonPath('data.0.relationships.advertisers.data', [['type' => 'advertisers', 'id' => '1'], ['type' => 'advertisers', 'id' => '2']]);
        $runs->assertJsonPath('data.1.relationships.advertisers.data', [['type' => 'advertisers', 'id' => '3']]);
        $runs->assertJsonPath('data.2.relationships.advertisers.data', []);
        self::assertSame([], $this->included($runs));

        $this->assertOneBatchEach();
    }

    #[Test]
    public function aPageIncludesFromOneBatchPerRelation(): void
    {
        $runs = $this->read('/api/runs?include=progress,advertisers');

        $runs->assertJsonPath('data.0.relationships.progress.data', ['type' => 'progresses', 'id' => 'p1']);
        $runs->assertJsonPath('data.2.relationships.progress.data', null);
        $runs->assertJsonPath('data.1.relationships.advertisers.data', [['type' => 'advertisers', 'id' => '3']]);
        self::assertSame(['advertisers:1', 'advertisers:2', 'advertisers:3', 'progresses:p1', 'progresses:p2'], $this->included($runs));

        $this->assertOneBatchEach();
    }

    #[Test]
    public function aSingleRunRendersItsProgressFromTheBatch(): void
    {
        $run = $this->read('/api/runs/2?include=progress');

        $run->assertJsonPath('data.relationships.progress.data', ['type' => 'progresses', 'id' => 'p2']);
        $run->assertJsonPath('included.0.attributes.percent', 100);
        self::assertSame([['runs', ['2']]], $this->progresses()->batchParents);
    }

    #[Test]
    public function theRelatedEndpointServesTheBatchedTarget(): void
    {
        $this->read('/api/runs/1/progress')
            ->assertJsonPath('data.type', 'progresses')
            ->assertJsonPath('data.id', 'p1')
            ->assertJsonPath('data.attributes.percent', 40);
        self::assertSame([['runs', ['1']]], $this->progresses()->batchParents);

        $this->read('/api/runs/3/progress')->assertJsonPath('data', null);
    }

    #[Test]
    public function theRelationshipEndpointServesTheBatchedTarget(): void
    {
        $this->read('/api/runs/2/relationships/progress')
            ->assertJsonPath('data', ['type' => 'progresses', 'id' => 'p2']);
        self::assertSame([['runs', ['2']]], $this->progresses()->batchParents);

        $this->read('/api/runs/3/relationships/progress')->assertJsonPath('data', null);
    }

    #[Test]
    public function aPageStillBatchesWithIncludeBatchingOff(): void
    {
        $batcher = $this->app?->make(RelatedIncludeBatcher::class);
        self::assertInstanceOf(RelatedIncludeBatcher::class, $batcher);
        $batcher->disable();

        $runs = $this->read('/api/runs?include=progress,advertisers');

        $runs->assertJsonPath('data.1.relationships.progress.data', ['type' => 'progresses', 'id' => 'p2']);
        self::assertSame(['advertisers:1', 'advertisers:2', 'advertisers:3', 'progresses:p1', 'progresses:p2'], $this->included($runs));
        $this->assertOneBatchEach();
    }

    #[Test]
    public function aRelationshipCountComesFromABatch(): void
    {
        $runs = $this->read('/api/runs?withCount=advertisers', self::MEDIA_TYPE . ';profile="' . self::COUNTABLE . '"');

        self::assertSame(2, $runs->json('data.0.relationships.advertisers.meta.total'));
        self::assertSame(1, $runs->json('data.1.relationships.advertisers.meta.total'));
        self::assertSame(0, $runs->json('data.2.relationships.advertisers.meta.total'));
        self::assertSame(0, $this->advertisers()->relatedCalls);
    }

    #[Test]
    public function aRelationshipQueriesWindowComesFromABatch(): void
    {
        $runs = $this->read('/api/runs?include=advertisers&relatedQuery[advertisers][sort]=name', self::MEDIA_TYPE . ';profile="' . self::RELATIONSHIP_QUERIES . '"');

        $runs->assertJsonPath('data.0.relationships.advertisers.data', [['type' => 'advertisers', 'id' => '1'], ['type' => 'advertisers', 'id' => '2']]);
        $runs->assertJsonPath('data.1.relationships.advertisers.data', [['type' => 'advertisers', 'id' => '3']]);
        self::assertSame(0, $this->advertisers()->relatedCalls);
    }

    #[Test]
    public function aToOneFilterMatchesTheBatchedTarget(): void
    {
        $runs = $this->read('/api/runs?include=progress&relatedQuery[progress][filter][percent]=100', self::MEDIA_TYPE . ';profile="' . self::RELATIONSHIP_QUERIES . '"');

        $runs->assertJsonPath('data.0.relationships.progress.data', null);
        $runs->assertJsonPath('data.1.relationships.progress.data', ['type' => 'progresses', 'id' => 'p2']);
        $runs->assertJsonPath('data.2.relationships.progress.data', null);
        self::assertSame(['progresses:p2'], $this->included($runs));
        self::assertSame(1, $this->progresses()->batchCalls);

        $this->read('/api/runs/1/progress?filter[percent]=100')->assertJsonPath('data', null);
        $this->read('/api/runs/2/progress?filter[percent]=100')->assertJsonPath('data.id', 'p2');
    }

    private function assertOneBatchEach(): void
    {
        self::assertSame([['runs', ['1', '2', '3']]], $this->progresses()->batchParents);
        self::assertSame(1, $this->progresses()->batchCalls);
        self::assertSame(0, $this->progresses()->relatedCalls);
        self::assertSame([['runs', ['1', '2', '3']]], $this->advertisers()->batchParents);
        self::assertSame(1, $this->advertisers()->batchCalls);
        self::assertSame(0, $this->advertisers()->relatedCalls);
    }
}
