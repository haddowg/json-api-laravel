<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\LoadedLinkage;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes\HidesHiddenRows;
use Illuminate\Support\ServiceProvider;

/**
 * Template 1 holds binding 1, which every read serves, binding 2, which the bindings
 * type's own provider hides, and binding 3, which its default filter hides.
 */
final class LoadedLinkageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([TemplateResource::class, BindingResource::class]);

        if (config('loaded_linkage.store') === 'memory') {
            $bindings = [
                '1' => new BindingRecord('1', 'Visible Binding'),
                '2' => new BindingRecord('2', 'Scoped Out Binding', hidden: true),
                '3' => new BindingRecord('3', 'Detached Binding', detached: true),
            ];
            $template = new TemplateRecord('1', 'Template One', \array_values($bindings), \array_values($bindings));

            JsonApi::provider(new InMemoryDataProvider('templates', ['1' => $template]));
            JsonApi::provider(new HidesHiddenRows('bindings', new InMemoryDataProvider('bindings', $bindings)));

            return;
        }

        $eloquent = new EloquentDataProvider([
            'templates' => Template::class,
            'bindings' => Binding::class,
        ]);
        JsonApi::provider($eloquent, priority: -128);
        JsonApi::provider(new HidesHiddenRows('bindings', $eloquent));

        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }
}
