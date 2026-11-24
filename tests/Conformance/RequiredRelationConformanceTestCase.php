<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A relation declared required is enforced as a required attribute is: a create must
 * send it, and a write that sends it may not send it empty. Asserted identically on the
 * in-memory witness ({@see InMemoryRequiredRelationConformanceTest}) and the reference
 * Eloquent provider ({@see EloquentRequiredRelationConformanceTest}). Every task has an
 * owner, and a new task names at least one watcher. Task 1 is owned and watched by
 * member 1.
 */
#[Group('spec:crud')]
abstract class RequiredRelationConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    public const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    // The illuminate `required` message a required attribute gives.
    private const string OWNER = 'The owner field is required.';

    private const string WATCHERS = 'The watchers field is required.';

    /**
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            $this->conformanceServiceProvider(),
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.base_uri', 'http://localhost/api');
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    public function aCreateWithoutRelationshipsIsRefused(): void
    {
        $response = $this->writeJsonApi('POST', '/api/tasks', ['data' => ['type' => 'tasks', 'id' => '9', 'attributes' => ['title' => 'Ship']]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([
            $this->violation(self::OWNER, '/data/relationships/owner'),
            $this->violation(self::WATCHERS, '/data/relationships/watchers'),
        ], $response->json('errors'));
        $this->getJson('/api/tasks/9', ['Accept' => self::MEDIA_TYPE])->assertNotFound();
    }

    #[Test]
    public function aCreateWithEmptyRelationshipsIsRefused(): void
    {
        $response = $this->writeJsonApi('POST', '/api/tasks', ['data' => ['type' => 'tasks', 'id' => '9', 'attributes' => ['title' => 'Ship'], 'relationships' => new \stdClass()]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([
            $this->violation(self::OWNER, '/data/relationships/owner'),
            $this->violation(self::WATCHERS, '/data/relationships/watchers'),
        ], $response->json('errors'));
        $this->getJson('/api/tasks/9', ['Accept' => self::MEDIA_TYPE])->assertNotFound();
    }

    #[Test]
    public function aCreateWithANullOwnerIsRefused(): void
    {
        $response = $this->writeJsonApi('POST', '/api/tasks', $this->task('9', null, ['1']));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::OWNER, '/data/relationships/owner/data')], $response->json('errors'));
        $this->getJson('/api/tasks/9', ['Accept' => self::MEDIA_TYPE])->assertNotFound();
    }

    #[Test]
    public function aCreateWithNoWatchersIsRefused(): void
    {
        $response = $this->writeJsonApi('POST', '/api/tasks', $this->task('9', '1', []));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::WATCHERS, '/data/relationships/watchers/data')], $response->json('errors'));
        $this->getJson('/api/tasks/9', ['Accept' => self::MEDIA_TYPE])->assertNotFound();
    }

    #[Test]
    public function aCreateWithEveryRequiredRelationshipIsSaved(): void
    {
        $response = $this->writeJsonApi('POST', '/api/tasks', $this->task('9', '2', ['1', '2']));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('2', $this->ownerOf('9'));
    }

    #[Test]
    public function anUpdateSettingTheOwnerToNullIsRefused(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/tasks/1', ['data' => ['type' => 'tasks', 'id' => '1', 'relationships' => ['owner' => ['data' => null]]]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::OWNER, '/data/relationships/owner/data')], $response->json('errors'));
        self::assertSame('1', $this->ownerOf('1'));
    }

    #[Test]
    public function anUpdateLeavingTheRelationshipsOutIsSaved(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/tasks/1', ['data' => ['type' => 'tasks', 'id' => '1', 'attributes' => ['title' => 'Replan']]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('1', $this->ownerOf('1'));
    }

    #[Test]
    public function anUpdateMayEmptyARelationRequiredOnlyOnCreate(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/tasks/1', ['data' => ['type' => 'tasks', 'id' => '1', 'relationships' => ['watchers' => ['data' => []]]]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function clearingTheOwnerThroughItsRelationshipEndpointIsRefused(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/tasks/1/relationships/owner', ['data' => null]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::OWNER, '/data')], $response->json('errors'));
        self::assertSame('1', $this->ownerOf('1'));
    }

    #[Test]
    public function anAtomicAddWithoutTheOwnerIsRefused(): void
    {
        $response = $this->atomic([
            ['op' => 'add', 'data' => $this->task('8', '1', ['1'])['data']],
            ['op' => 'add', 'data' => ['type' => 'tasks', 'id' => '9', 'attributes' => ['title' => 'Ship'], 'relationships' => ['watchers' => ['data' => [['type' => 'members', 'id' => '1']]]]]],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::OWNER, '/atomic:operations/1/data/relationships/owner')], $response->json('errors'));
        $this->getJson('/api/tasks/8', ['Accept' => self::MEDIA_TYPE])->assertNotFound();
        $this->getJson('/api/tasks/9', ['Accept' => self::MEDIA_TYPE])->assertNotFound();
    }

    #[Test]
    public function anAtomicAddWithEveryRequiredRelationshipCommits(): void
    {
        $response = $this->atomic([
            ['op' => 'add', 'data' => $this->task('9', '2', ['2'])['data']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('2', $this->ownerOf('9'));
    }

    #[Test]
    public function anActionTakingTheTypesCreateRulesRefusesAMissingOwner(): void
    {
        $response = $this->writeJsonApi('POST', '/api/tasks/-actions/draft', ['data' => ['type' => 'tasks', 'attributes' => ['title' => 'Ship'], 'relationships' => ['watchers' => ['data' => [['type' => 'members', 'id' => '1']]]]]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::OWNER, '/data/relationships/owner')], $response->json('errors'));
    }

    /**
     * @param list<string> $watchers
     *
     * @return array{data: array<string, mixed>}
     */
    private function task(string $id, ?string $owner, array $watchers): array
    {
        return ['data' => [
            'type' => 'tasks',
            'id' => $id,
            'attributes' => ['title' => 'Ship'],
            'relationships' => [
                'owner' => ['data' => $owner === null ? null : ['type' => 'members', 'id' => $owner]],
                'watchers' => ['data' => \array_map(static fn(string $member): array => ['type' => 'members', 'id' => $member], $watchers)],
            ],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function violation(string $detail, string $pointer): array
    {
        return [
            'status' => '422',
            'code' => 'VALIDATION_FAILED',
            'title' => 'Unprocessable Entity',
            'detail' => $detail,
            'source' => ['pointer' => $pointer],
        ];
    }

    private function ownerOf(string $id): ?string
    {
        $response = $this->getJson('/api/tasks/' . $id . '/relationships/owner', ['Accept' => self::MEDIA_TYPE]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $response->json('data');
        if ($data === null) {
            return null;
        }
        self::assertIsArray($data);
        self::assertIsString($data['id'] ?? null);

        return $data['id'];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function writeJsonApi(string $method, string $uri, array $document): TestResponse
    {
        return $this->json($method, $uri, $document, [
            'Accept' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function atomic(array $operations): TestResponse
    {
        return $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => self::ATOMIC_MEDIA_TYPE,
            'CONTENT_TYPE' => self::ATOMIC_MEDIA_TYPE,
        ], \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR));
    }
}
