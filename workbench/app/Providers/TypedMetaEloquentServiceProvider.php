<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Event\AfterFetchCollectionEvent;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\TmBoard;
use Workbench\App\Models\TmCard;
use Workbench\App\TypedMeta\PinCard;
use Workbench\App\TypedMeta\TmBoardResource;
use Workbench\App\TypedMeta\TmCardResource;
use Workbench\App\TypedMeta\TmLabel;
use Workbench\App\TypedMeta\TmLabelSerializer;
use Workbench\App\TypedMeta\TmMetaListener;

/**
 * The typed-document-meta suite on the reference Eloquent provider and persister, with the
 * composed labels type served from memory.
 */
final class TypedMetaEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([TmCardResource::class, TmBoardResource::class, TmLabelSerializer::class, PinCard::class]);

        $modelByType = ['tm-cards' => TmCard::class, 'tm-boards' => TmBoard::class];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
        JsonApi::provider(new InMemoryDataProvider(
            'tm-labels',
            ['urgent' => new TmLabel('urgent', 'Urgent'), 'later' => new TmLabel('later', 'Later')],
            identify: static fn(object $label): string => $label instanceof TmLabel ? $label->id : '',
        ));
    }

    public function boot(): void
    {
        Event::listen(AfterFetchCollectionEvent::class, [TmMetaListener::class, 'handle']);
    }
}
