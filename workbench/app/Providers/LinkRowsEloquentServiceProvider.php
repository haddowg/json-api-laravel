<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\LinkRows\BoardResource;
use Workbench\App\LinkRows\NoteResource;
use Workbench\App\LinkRows\PhotoResource;
use Workbench\App\LinkRows\TagBoardResource;
use Workbench\App\Models\Board;
use Workbench\App\Models\Note;
use Workbench\App\Models\Photo;

/**
 * The Eloquent half of the link row conformance wiring.
 */
final class LinkRowsEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([BoardResource::class, NoteResource::class, PhotoResource::class]);

        $modelByType = ['boards' => Board::class, 'notes' => Note::class, 'photos' => Photo::class];

        // The same boards as `tag-boards`, whose link rows take a tag through an open pivot.
        if (config('link_rows.tags') === true) {
            JsonApi::register([TagBoardResource::class]);
            $modelByType['tag-boards'] = Board::class;
        }

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }
}
