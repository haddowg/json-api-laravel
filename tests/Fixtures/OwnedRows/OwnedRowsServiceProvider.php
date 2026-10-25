<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryStore;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires `projects` and `tasks` over the store named by `owned.store`: `eloquent`, where the
 * {@see Task} model carries the {@see OwnedByCurrentUser} global scope, or `memory`, where the
 * {@see OwnTasksOnly} provider owns `tasks`.
 *
 * Seed: project 1 holds task 1 "Draft" and task 3 "Ship" owned by user 1, and task 2 "Review"
 * owned by user 2.
 *
 * @internal
 */
final class OwnedRowsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ProjectResource::class, TaskResource::class]);

        if (config('owned.store') === 'memory') {
            $tasks = [
                '1' => new TaskRecord('1', 'Draft', '1'),
                '2' => new TaskRecord('2', 'Review', '2'),
                '3' => new TaskRecord('3', 'Ship', '1'),
            ];
            $projects = ['1' => new ProjectRecord('1', 'Launch', \array_values($tasks))];

            JsonApi::provider(new InMemoryDataProvider('projects', $projects, identify: static fn(object $project): string => $project instanceof ProjectRecord ? $project->id : ''));
            JsonApi::provider(new OwnTasksOnly($tasks));
            JsonApi::persister(new InMemoryDataPersister('tasks', new InMemoryStore($tasks), static fn(): TaskRecord => new TaskRecord()));

            return;
        }

        $models = ['projects' => Project::class, 'tasks' => Task::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }
}
