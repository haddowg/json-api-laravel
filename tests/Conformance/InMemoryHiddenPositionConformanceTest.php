<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Positioned\Queue;
use Workbench\App\Providers\PositionedHiddenInMemoryServiceProvider;

/**
 * {@see HiddenPositionConformanceTestCase} against the in-memory witness, song 2 hidden by a
 * songs provider of its own.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryHiddenPositionConformanceTest extends HiddenPositionConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return PositionedHiddenInMemoryServiceProvider::class;
    }

    protected function storedMix(): array
    {
        $provider = $this->app?->make(DataProviderRegistry::class)->forType('queues');
        \assert($provider instanceof InMemoryDataProvider);
        $queue = $provider->store()->find('1');
        \assert($queue instanceof Queue);

        $positions = [];
        foreach ($queue->mixRows as $row) {
            $song = Accessor::get($row, 'song_id');
            $at = Accessor::get($row, 'position');
            \assert(\is_int($song) && \is_int($at));
            $positions[$song] = $at;
        }
        \asort($positions);

        return $positions;
    }
}
