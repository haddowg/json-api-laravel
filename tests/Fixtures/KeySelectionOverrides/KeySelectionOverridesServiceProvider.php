<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\KeySelectionOverrides;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Serves one seeded board as the `boards` and the `easels` types, each rendered through
 * its serializer override.
 *
 * @internal
 */
final class KeySelectionOverridesServiceProvider extends ServiceProvider
{
    public const array VALUES = ['cap' => '10', 'countries' => 'GB,FR', 'region' => 'EU'];

    public function register(): void
    {
        JsonApi::register([BoardResource::class, EaselResource::class]);

        $identify = static fn(object $item): string => $item instanceof Board ? $item->id : '';
        $board = new Board(
            '1',
            'Seed',
            self::VALUES,
            ['street' => '1 High Street', 'postcode' => 'AB1 2CD', 'geo' => ['lat' => 51.5, 'lng' => -0.1]],
            [['label' => 'A', 'size' => ['w' => 1, 'h' => 2]], ['label' => 'B', 'size' => ['w' => 3, 'h' => 4]]],
        );

        JsonApi::provider(new InMemoryDataProvider('boards', ['1' => $board], identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('easels', ['1' => $board], identify: $identify));
    }
}
