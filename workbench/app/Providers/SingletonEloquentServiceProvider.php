<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Inbox;
use Workbench\App\Models\InboxItem;
use Workbench\App\Models\InboxLabel;
use Workbench\App\Singleton\ArchiveInbox;
use Workbench\App\Singleton\InboxItemResource;
use Workbench\App\Singleton\InboxLabelResource;
use Workbench\App\Singleton\InboxResource;

/**
 * The singleton suite on the reference Eloquent provider and persister.
 */
final class SingletonEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([InboxResource::class, InboxItemResource::class, InboxLabelResource::class, ArchiveInbox::class]);

        $modelByType = ['inboxes' => Inbox::class, 'inboxItems' => InboxItem::class, 'inboxLabels' => InboxLabel::class];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }
}
