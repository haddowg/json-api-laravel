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
 * A write that supplies a to-many's whole member set must respect the relation's
 * minItems() and maxItems(), asserted identically on the in-memory witness
 * ({@see InMemoryRelationBoundsConformanceTest}) and the reference Eloquent provider
 * ({@see EloquentRelationBoundsConformanceTest}). Squads field one or two players;
 * squad 1 fields player 1.
 */
#[Group('spec:crud')]
abstract class RelationBoundsConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    public const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    // The same illuminate `min`/`max` messages a list attribute's minItems()/maxItems() gives.
    private const string TOO_FEW = 'The players field must have at least 1 items.';

    private const string TOO_MANY = 'The players field must not have more than 2 items.';

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
    public function creatingWithTooFewMembersIsRefused(): void
    {
        $response = $this->writeJsonApi('POST', '/api/squads', $this->squad('9', []));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::TOO_FEW, '/data/relationships/players/data')], $response->json('errors'));
        $this->getJson('/api/squads/9', ['Accept' => self::MEDIA_TYPE])->assertNotFound();
    }

    #[Test]
    public function creatingWithTooManyMembersIsRefused(): void
    {
        $response = $this->writeJsonApi('POST', '/api/squads', $this->squad('9', ['1', '2', '3']));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::TOO_MANY, '/data/relationships/players/data')], $response->json('errors'));
        $this->getJson('/api/squads/9', ['Accept' => self::MEDIA_TYPE])->assertNotFound();
    }

    #[Test]
    public function creatingWithinTheBoundsIsSaved(): void
    {
        $response = $this->writeJsonApi('POST', '/api/squads', $this->squad('9', ['1', '2']));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '2'], $this->playersOf('9'));
    }

    #[Test]
    public function aRepeatedMemberCountsOnce(): void
    {
        $response = $this->writeJsonApi('POST', '/api/squads', $this->squad('9', ['1', '2', '1']));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '2'], $this->playersOf('9'));
    }

    #[Test]
    public function creatingWithoutTheRelationshipIsNotChecked(): void
    {
        $response = $this->writeJsonApi('POST', '/api/squads', ['data' => ['type' => 'squads', 'id' => '9', 'attributes' => ['name' => 'Blues']]]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function updatingWithTooFewMembersIsRefused(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/squads/1', $this->squad('1', []));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::TOO_FEW, '/data/relationships/players/data')], $response->json('errors'));
        self::assertSame(['1'], $this->playersOf('1'));
    }

    #[Test]
    public function updatingWithTooManyMembersIsRefused(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/squads/1', $this->squad('1', ['1', '2', '3']));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::TOO_MANY, '/data/relationships/players/data')], $response->json('errors'));
        self::assertSame(['1'], $this->playersOf('1'));
    }

    #[Test]
    public function updatingAttributesAloneIsNotChecked(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/squads/1', ['data' => ['type' => 'squads', 'id' => '1', 'attributes' => ['name' => 'Blues']]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function replacingWithTooFewMembersIsRefused(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/squads/1/relationships/players', ['data' => []]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            '{"errors":[{"status":"422","code":"VALIDATION_FAILED","title":"Unprocessable Entity","detail":"The players field must have at least 1 items.","source":{"pointer":"\/data"}}],"jsonapi":{"version":"1.1"}}',
            $response->getContent(),
        );
        self::assertSame(['1'], $this->playersOf('1'));
    }

    #[Test]
    public function replacingWithTooManyMembersIsRefused(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/squads/1/relationships/players', ['data' => $this->identifiers(['1', '2', '3'])]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::TOO_MANY, '/data')], $response->json('errors'));
        self::assertSame(['1'], $this->playersOf('1'));
    }

    #[Test]
    public function replacingWithinTheBoundsIsSaved(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/squads/1/relationships/players', ['data' => $this->identifiers(['2', '3'])]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['2', '3'], $this->playersOf('1'));
    }

    #[Test]
    public function anAtomicResourceUpdateIsChecked(): void
    {
        $response = $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'squads', 'id' => '1'], 'data' => ['type' => 'squads', 'id' => '1', 'attributes' => ['name' => 'Blues']]],
            ['op' => 'update', 'ref' => ['type' => 'squads', 'id' => '1'], 'data' => $this->squad('1', ['1', '2', '3'])['data']],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::TOO_MANY, '/atomic:operations/1/data/relationships/players/data')], $response->json('errors'));
        self::assertSame(['1'], $this->playersOf('1'));
    }

    #[Test]
    public function anAtomicRelationshipReplaceIsChecked(): void
    {
        $response = $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'squads', 'id' => '1', 'relationship' => 'players'], 'data' => $this->identifiers(['2'])],
            ['op' => 'update', 'ref' => ['type' => 'squads', 'id' => '1', 'relationship' => 'players'], 'data' => []],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::TOO_FEW, '/atomic:operations/1/data')], $response->json('errors'));
        self::assertSame(['1'], $this->playersOf('1'));
    }

    #[Test]
    public function anAtomicBatchWithinTheBoundsCommits(): void
    {
        $response = $this->atomic([
            ['op' => 'add', 'data' => $this->squad('9', ['3'])['data']],
            ['op' => 'update', 'ref' => ['type' => 'squads', 'id' => '1', 'relationship' => 'players'], 'data' => $this->identifiers(['1', '2'])],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['3'], $this->playersOf('9'));
        self::assertSame(['1', '2'], $this->playersOf('1'));
    }

    /**
     * @param list<string> $players
     *
     * @return array{data: array<string, mixed>}
     */
    private function squad(string $id, array $players): array
    {
        return ['data' => [
            'type' => 'squads',
            'id' => $id,
            'attributes' => ['name' => 'Blues'],
            'relationships' => ['players' => ['data' => $this->identifiers($players)]],
        ]];
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array{type: string, id: string}>
     */
    private function identifiers(array $ids): array
    {
        return \array_map(static fn(string $id): array => ['type' => 'players', 'id' => $id], $ids);
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

    /**
     * @return list<string>
     */
    private function playersOf(string $id): array
    {
        $response = $this->getJson('/api/squads/' . $id . '/relationships/players', ['Accept' => self::MEDIA_TYPE]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $response->json('data');
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertIsString($identifier['id'] ?? null);
            $ids[] = $identifier['id'];
        }
        \sort($ids);

        return $ids;
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
