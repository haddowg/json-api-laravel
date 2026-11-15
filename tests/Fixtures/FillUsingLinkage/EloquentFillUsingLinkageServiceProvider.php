<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsingLinkage;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * @internal
 */
final class EloquentFillUsingLinkageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([MemoResource::class, StickyResource::class, LabelResource::class, TemplateSettingResource::class]);

        $models = ['memos' => Memo::class, 'stickies' => Sticky::class, 'labels' => Label::class];
        JsonApi::provider(new TemplateSettingsProvider());
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
    }
}
