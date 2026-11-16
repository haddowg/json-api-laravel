<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the first-page fixtures over the store `first_page.store` names: `eloquent` or
 * `memory`, each seeded from {@see FirstPageSeed}.
 */
final class FirstPageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([FpDeskResource::class, FpBoardResource::class, FpCardResource::class, FpCommentResource::class, FpLabelResource::class]);

        if (config('first_page.store') === 'memory') {
            $identify = static fn(object $row): string => \property_exists($row, 'id') && \is_string($row->id) ? $row->id : '';
            foreach (FirstPageSeed::records() as $type => $rows) {
                $provider = new InMemoryDataProvider($type, $rows, identify: $identify);
                JsonApi::provider($provider);
                if ($type === 'fp-boards') {
                    JsonApi::persister(new InMemoryDataPersister('fp-boards', $provider->store(), static fn(): FpBoardRecord => new FpBoardRecord()));
                }
            }

            return;
        }

        $models = [
            'fp-desks' => FpDesk::class,
            'fp-boards' => FpBoard::class,
            'fp-cards' => FpCard::class,
            'fp-comments' => FpComment::class,
            'fp-labels' => FpLabel::class,
        ];
        JsonApi::provider(new EloquentDataProvider($models));
        JsonApi::persister(new EloquentDataPersister($models));
        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }
}
