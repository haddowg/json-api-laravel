<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Member;
use Workbench\App\Models\Task;
use Workbench\App\RequiredRelation\DraftTaskAction;
use Workbench\App\RequiredRelation\MemberResource;
use Workbench\App\RequiredRelation\TaskResource;

/**
 * The Eloquent half of the required-relation conformance wiring.
 */
final class RequiredRelationEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([TaskResource::class, MemberResource::class, DraftTaskAction::class]);

        $modelByType = ['tasks' => Task::class, 'members' => Member::class];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }
}
