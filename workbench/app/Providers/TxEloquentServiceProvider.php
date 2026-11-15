<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\TxNote;
use Workbench\App\Models\TxWidget;
use Workbench\App\WriteTransaction\PeekTxWidget;
use Workbench\App\WriteTransaction\StampTxWidget;
use Workbench\App\WriteTransaction\SweepTxWidgets;
use Workbench\App\WriteTransaction\TxAcceptingPersister;
use Workbench\App\WriteTransaction\TxListener;
use Workbench\App\WriteTransaction\TxNoteResource;
use Workbench\App\WriteTransaction\TxWidgetResource;

/**
 * The write-transaction suite on the reference Eloquent provider and persister.
 */
final class TxEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([TxWidgetResource::class, TxNoteResource::class, StampTxWidget::class, SweepTxWidgets::class, PeekTxWidget::class]);

        $modelByType = ['txWidgets' => TxWidget::class, 'txNotes' => TxNote::class];
        $persister = new EloquentDataPersister($modelByType);

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister($persister, priority: -128);
        JsonApi::persister(new TxAcceptingPersister($persister));
    }

    public function boot(Dispatcher $events): void
    {
        TxListener::listen($events);
    }
}
