<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\OwnedRows;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows\OwnedRowsServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows\Project;
use haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows\ProjectPolicy;
use haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows\ProjectRecord;
use haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows\Task;
use haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows\TaskPolicy;
use haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows\TaskRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Backs the authorization guide's per-user scoping examples: `tasks` is limited to the signed-in
 * user's rows, by a global scope or by a provider, and every list and count that reaches it
 * agrees with that. User 1 owns tasks 1 and 3 of project 1; user 2 owns task 2.
 *
 * @internal
 */
abstract class OwnedRowsTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string COUNTABLE = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/countable/"';

    private const string QUERIES_AND_COUNTABLE = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/relationship-queries/ https://haddowg.github.io/json-api/profiles/countable/"';

    /**
     * `eloquent` or `memory`.
     */
    abstract protected function store(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('owned.store', $this->store());

        return [JsonApiServiceProvider::class, OwnedRowsServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(new User(['id' => 1, 'name' => 'Ada']));
    }

    #[Test]
    public function theCollectionListsOnlyTheUsersRows(): void
    {
        $tasks = $this->read('/api/tasks?page[size]=10&withCount=_self_', self::COUNTABLE);

        self::assertSame(['1', '3'], $this->ids($tasks->json('data')));
        $tasks->assertJsonPath('meta.page.total', 2);
        $this->get('/api/tasks/2', ['Accept' => self::MEDIA_TYPE])->assertNotFound();
    }

    #[Test]
    public function aRelationshipCountsOnlyTheUsersRows(): void
    {
        $project = $this->read('/api/projects/1?include=tasks&withCount=tasks', self::COUNTABLE);

        self::assertSame(['1', '3'], $this->ids($project->json('data.relationships.tasks.data')));
        $project->assertJsonPath('data.relationships.tasks.meta.total', 2);
        self::assertSame(['1', '3'], $this->ids($project->json('included')));

        $related = $this->read('/api/projects/1/tasks?page[size]=1&withCount=_self_', self::COUNTABLE);
        self::assertSame(['1'], $this->ids($related->json('data')));
        $related->assertJsonPath('meta.total', 2);

        self::assertSame(['1', '3'], $this->ids($this->read('/api/projects/1/relationships/tasks')->json('data')));
    }

    #[Test]
    public function aRelationshipQueriesWindowAndItsCountCoverOnlyTheUsersRows(): void
    {
        // "Ship" and "Review" contain an i, but only Ship is the user's.
        $project = $this->read('/api/projects/1?include=tasks&withCount=tasks&relatedQuery[tasks][filter][title]=i', self::QUERIES_AND_COUNTABLE);

        self::assertSame(['3'], $this->ids($project->json('data.relationships.tasks.data')));
        $project->assertJsonPath('data.relationships.tasks.meta.total', 1);
    }

    #[Test]
    public function aRelationPermissionExposesTasksTheTypeWouldNotList(): void
    {
        $this->registerPolicies(withTaskPolicy: true);
        $this->actingAs(new User(['id' => 1, 'name' => 'Ada', 'can_read' => true, 'is_admin' => false]));

        $this->get('/api/tasks', ['Accept' => self::MEDIA_TYPE])->assertForbidden();

        $project = $this->read('/api/projects/1?include=tasks&withCount=tasks', self::COUNTABLE);
        self::assertSame(['1', '3'], $this->ids($project->json('data.relationships.tasks.data')));
        $project->assertJsonPath('data.relationships.tasks.meta.total', 2);
        self::assertSame(['1', '3'], $this->ids($this->read('/api/projects/1/tasks')->json('data')));
    }

    #[Test]
    public function aRelationPermissionBlocksTasksTheTypeWouldList(): void
    {
        $this->registerPolicies(withTaskPolicy: false);
        $this->actingAs(new User(['id' => 1, 'name' => 'Ada', 'can_read' => false, 'is_admin' => false]));

        self::assertSame(['1', '3'], $this->ids($this->read('/api/tasks')->json('data')));

        $relationship = $this->read('/api/projects/1?include=tasks')->json('data.relationships.tasks');
        self::assertIsArray($relationship);
        self::assertArrayNotHasKey('data', $relationship);
        $this->get('/api/projects/1/tasks', ['Accept' => self::MEDIA_TYPE])->assertForbidden();
    }

    private function registerPolicies(bool $withTaskPolicy): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(ProjectRecord::class, ProjectPolicy::class);
        if ($withTaskPolicy) {
            Gate::policy(Task::class, TaskPolicy::class);
            Gate::policy(TaskRecord::class, TaskPolicy::class);
        }
    }

    #[Test]
    public function aTitleHeldOnlyByAHiddenTaskIsFree(): void
    {
        // "Review" belongs to user 2, so user 1 can take it.
        $this->write('POST', '/api/tasks', ['data' => ['type' => 'tasks', 'attributes' => ['title' => 'Review']]])->assertCreated();
    }

    #[Test]
    public function aTitleAVisibleTaskHoldsIs422(): void
    {
        $this->write('POST', '/api/tasks', ['data' => ['type' => 'tasks', 'attributes' => ['title' => 'Ship']]])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/title');
    }

    #[Test]
    public function anUpdateKeepingItsOwnTitlePasses(): void
    {
        $this->write('PATCH', '/api/tasks/1', ['data' => ['type' => 'tasks', 'id' => '1', 'attributes' => ['title' => 'Draft']]])->assertOk();
        $this->write('PATCH', '/api/tasks/1', ['data' => ['type' => 'tasks', 'id' => '1', 'attributes' => []]])->assertOk();
    }

    #[Test]
    public function anUpdateToAVisibleTasksTitleIs422(): void
    {
        $this->write('PATCH', '/api/tasks/1', ['data' => ['type' => 'tasks', 'id' => '1', 'attributes' => ['title' => 'Ship']]])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/title');

        // A hidden task's title is free on update too.
        $this->write('PATCH', '/api/tasks/1', ['data' => ['type' => 'tasks', 'id' => '1', 'attributes' => ['title' => 'Review']]])->assertOk();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function write(string $method, string $uri, array $body): TestResponse
    {
        return $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE], (string) \json_encode($body));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function read(string $uri, string $accept = self::MEDIA_TYPE): TestResponse
    {
        return $this->get($uri, ['Accept' => $accept])->assertOk();
    }

    /**
     * @return list<string>
     */
    private function ids(mixed $resources): array
    {
        self::assertIsArray($resources);
        $ids = \array_map(static fn(mixed $resource): string => \is_array($resource) && \is_string($resource['id'] ?? null) ? $resource['id'] : '', $resources);
        \sort($ids);

        return $ids;
    }
}
