<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\ResponseDecl\Widget;
use Workbench\App\ResponseDecl\WidgetJob;
use Workbench\App\ResponseDecl\WidgetJobResource;
use Workbench\App\ResponseDecl\WidgetResource;

/**
 * Registers the response-declaration witnesses: the read-only {@see WidgetJobResource}
 * whose fetch-one redirects (`303`) once the job is done (seeded with a `processing` and a
 * `done` job pointing at the produced widget `1`), and the {@see WidgetResource} whose CRUD
 * operations declare explicit response sets exercised by the OpenAPI projection.
 */
final class ResponseDeclInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([WidgetJobResource::class, WidgetResource::class]);

        $jobs = new InMemoryDataProvider(
            'widget-jobs',
            [
                'pending' => new WidgetJob(id: 'pending', status: 'processing'),
                'done' => new WidgetJob(id: 'done', status: 'done', producedId: '1'),
            ],
            identify: self::identify(),
            assignId: self::assignId(),
        );
        JsonApi::provider($jobs);

        $widgets = new InMemoryDataProvider(
            'widgets',
            ['1' => new Widget(id: '1', name: 'Widget One')],
            identify: self::identify(),
            assignId: self::assignId(),
        );
        JsonApi::provider($widgets);
    }

    private static function identify(): \Closure
    {
        return static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
    }

    private static function assignId(): \Closure
    {
        return static function (object $item, string $id): void {
            Accessor::set($item, 'id', $id);
        };
    }
}
