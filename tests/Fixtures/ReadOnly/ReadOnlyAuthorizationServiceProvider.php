<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ReadOnly;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the persister-less read-only authorization fixtures: the deny-all
 * {@see ChartResource} and the policy-less {@see LabelResource}, each over a seeded
 * in-memory READ provider with NO persister (a genuinely read-only type). Registered on
 * the default (unguarded) server, so any denial comes from the policy — not auth
 * middleware — isolating the persister-less authorization path.
 */
final class ReadOnlyAuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ChartResource::class, LabelResource::class]);

        JsonApi::provider(new InMemoryDataProvider('charts', $this->charts()));
        JsonApi::provider(new InMemoryDataProvider('labels', $this->charts()));
    }

    /**
     * @return array<int|string, Chart>
     */
    private function charts(): array
    {
        return [
            '1' => new Chart(id: '1', title: 'Top 40'),
            '2' => new Chart(id: '2', title: 'Indie 100'),
        ];
    }
}
