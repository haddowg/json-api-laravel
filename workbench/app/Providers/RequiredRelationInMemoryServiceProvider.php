<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Domain\Member;
use Workbench\App\Domain\Task;
use Workbench\App\RequiredRelation\DraftTaskAction;
use Workbench\App\RequiredRelation\Fixtures;
use Workbench\App\RequiredRelation\MemberResource;
use Workbench\App\RequiredRelation\TaskResource;

/**
 * The in-memory half of the required-relation conformance wiring, seeded from
 * {@see Fixtures}. Both stores share one snapshot coordinator so an atomic rollback
 * restores the whole graph.
 */
final class RequiredRelationInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([TaskResource::class, MemberResource::class, DraftTaskAction::class]);

        $members = [];
        foreach (Fixtures::members() as $id => $name) {
            $members[(string) $id] = new Member((string) $id, $name);
        }

        $tasks = [];
        foreach (Fixtures::tasks() as $id => $row) {
            $watchers = [];
            foreach ($row['watchers'] as $memberId) {
                $watchers[] = $members[(string) $memberId];
            }
            $tasks[(string) $id] = new Task((string) $id, $row['title'], $members[(string) $row['owner']], $watchers);
        }

        /** @var InMemorySnapshotCoordinator $coordinator */
        $coordinator = $this->app->make(InMemorySnapshotCoordinator::class);

        $memberProvider = new InMemoryDataProvider('members', $members, coordinator: $coordinator);
        JsonApi::provider($memberProvider);

        $taskProvider = new InMemoryDataProvider('tasks', $tasks, identify: static fn(object $task): string => $task instanceof Task ? $task->id : '', coordinator: $coordinator);
        JsonApi::provider($taskProvider);

        $relatedResolver = static fn(string $type, string $id): ?object => $type === 'members' ? $memberProvider->store()->find($id) : null;
        JsonApi::persister(new InMemoryDataPersister('tasks', $taskProvider->store(), static fn(): Task => new Task(), $relatedResolver));
    }
}
