<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * `campaign-templates`, keyed by a non-incrementing `campaign_id`, and
 * `placeholder-definitions`, keyed by a binary UUID, joined by a custom pivot whose own
 * binary UUID id its constructor mints.
 *
 * @internal
 */
final class PivotKeysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([PkTemplateResource::class, PkDefinitionResource::class, PkLabelledDefinitionResource::class]);

        // Labelled definitions come first, so a lookup by model class alone would pick their encoder.
        $models = ['labelled-definitions' => PkDefinition::class, 'campaign-templates' => PkTemplate::class, 'placeholder-definitions' => PkDefinition::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }
}
