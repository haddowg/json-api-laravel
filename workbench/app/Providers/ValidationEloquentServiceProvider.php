<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Article;
use Workbench\App\Models\Checklist;
use Workbench\App\Validation\ArticleResource;
use Workbench\App\Validation\ChecklistResource;

/**
 * The **Eloquent** half of the validation-conformance wiring: it registers the SAME
 * {@see ArticleResource} the in-memory half serves and the reference
 * {@see EloquentDataProvider} / {@see EloquentDataPersister} pair at `-128`
 * over a `articles → model` map. On this provider the `slug`
 * {@see \haddowg\JsonApiLaravel\Validation\Constraint\UniqueEntity} resolves to a real
 * `Rule::unique` query against the table (self-excluded on update) — the uniqueness
 * witness the in-memory provider cannot supply.
 */
final class ValidationEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ArticleResource::class, ChecklistResource::class]);

        $modelByType = ['articles' => Article::class, 'checklists' => Checklist::class];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }
}
