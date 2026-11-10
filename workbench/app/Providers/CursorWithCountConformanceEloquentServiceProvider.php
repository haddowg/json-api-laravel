<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\CursorBoard;
use Workbench\App\Models\CursorGroup;
use Workbench\App\Models\CursorWidget;

/**
 * The cursor widgets with both of their parents, the groups and the pivot-bearing boards,
 * over the Eloquent provider, for the `?withCount` on cursor pages conformance suite.
 */
final class CursorWithCountConformanceEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([\dirname(__DIR__) . '/Cursor', \dirname(__DIR__) . '/CursorRelated', \dirname(__DIR__) . '/CursorPivot']);

        JsonApi::provider(
            new EloquentDataProvider([
                'cursorWidgets' => CursorWidget::class,
                'cursorGroups' => CursorGroup::class,
                'cursorBoards' => CursorBoard::class,
            ]),
            priority: -128,
        );
    }
}
