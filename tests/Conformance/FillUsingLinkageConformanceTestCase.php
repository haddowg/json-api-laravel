<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\FillUsingLinkage\Memo;
use haddowg\JsonApiLaravel\Tests\Fixtures\FillUsingLinkage\MemoRecord;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A fill hook gets bare identifiers and no persister resolves them, so every id a write names
 * must be checked against its type's provider before the hook runs: one a custom provider
 * doesn't hold and one the reference provider has no row for are both a `404`, and nothing is
 * stored. Asserted identically on the in-memory witness
 * ({@see InMemoryFillUsingLinkageConformanceTest}) and the reference Eloquent provider
 * ({@see EloquentFillUsingLinkageConformanceTest}).
 *
 * Seed: memo 1 "Todo" holds setting 1, settings [1], label 1 and labels [1]. Template settings
 * 1 and 2 and labels 1 and 2 exist.
 */
abstract class FillUsingLinkageConformanceTestCase extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string ATOMIC = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

    /**
     * Each relation, with a body naming a missing row and one naming rows that exist.
     *
     * @var array<string, array{0: mixed, 1: mixed}>
     */
    private const array RELATIONS = [
        'setting' => [['type' => 'template-settings', 'id' => '9'], ['type' => 'template-settings', 'id' => '2']],
        'settings' => [[['type' => 'template-settings', 'id' => '2'], ['type' => 'template-settings', 'id' => '9']], [['type' => 'template-settings', 'id' => '2']]],
        'label' => [['type' => 'labels', 'id' => '9'], ['type' => 'labels', 'id' => '2']],
        'labels' => [[['type' => 'labels', 'id' => '2'], ['type' => 'labels', 'id' => '9']], [['type' => 'labels', 'id' => '2']]],
    ];

    /**
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    abstract protected function storedCount(string $type): int;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, $this->conformanceServiceProvider()];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    #[Test]
    public function aCreateNamingRowsTheProvidersServeWritesThemThroughTheHooks(): void
    {
        $relationships = [];
        foreach (self::RELATIONS as $name => [, $present]) {
            $relationships[$name] = ['data' => $present];
        }

        $created = $this->write('POST', '/api/memos', ['data' => ['type' => 'memos', 'attributes' => ['title' => 'Spec'], 'relationships' => $relationships]]);

        $created->assertCreated();
        $id = $created->json('data.id');
        self::assertIsString($id);
        self::assertSame(['2', ['2'], '2', ['2']], $this->stored($id));
    }

    #[Test]
    public function aCreateNamingAMissingRowIsNotFoundAndStoresNothing(): void
    {
        foreach (self::RELATIONS as $name => [$missing]) {
            $response = $this->write('POST', '/api/memos', ['data' => [
                'type' => 'memos',
                'attributes' => ['title' => 'Spec'],
                'relationships' => [$name => ['data' => $missing]],
            ]]);
            $this->assertNotFound($response, $name);
        }

        $this->assertNotFound($this->write('POST', '/api/stickies', ['data' => [
            'type' => 'stickies',
            'attributes' => ['title' => 'Spec'],
            'relationships' => ['label' => ['data' => ['type' => 'labels', 'id' => '9']]],
        ]]), 'stickies');

        self::assertSame(1, $this->storedCount('memos'));
        self::assertSame(0, $this->storedCount('stickies'));
    }

    #[Test]
    public function anUpdateNamingAMissingRowIsNotFoundAndChangesNothing(): void
    {
        foreach (self::RELATIONS as $name => [$missing]) {
            $response = $this->write('PATCH', '/api/memos/1', ['data' => [
                'type' => 'memos',
                'id' => '1',
                'attributes' => ['title' => 'Renamed'],
                'relationships' => [$name => ['data' => $missing]],
            ]]);
            $this->assertNotFound($response, $name);
        }

        self::assertSame(['1', ['1'], '1', ['1']], $this->stored('1'));
        $this->read('/api/memos/1')->assertJsonPath('data.attributes.title', 'Todo');
    }

    #[Test]
    public function theRelationshipEndpointsRefuseAMissingRow(): void
    {
        foreach (self::RELATIONS as $name => [$missing]) {
            $this->assertNotFound($this->write('PATCH', '/api/memos/1/relationships/' . $name, ['data' => $missing]), 'PATCH ' . $name);
            if (\array_is_list($missing)) {
                $this->assertNotFound($this->write('POST', '/api/memos/1/relationships/' . $name, ['data' => $missing]), 'POST ' . $name);
            }
        }

        self::assertSame(['1', ['1'], '1', ['1']], $this->stored('1'));

        foreach (self::RELATIONS as $name => [, $present]) {
            $this->write('PATCH', '/api/memos/1/relationships/' . $name, ['data' => $present])->assertOk();
        }
        self::assertSame(['2', ['2'], '2', ['2']], $this->stored('1'));
    }

    #[Test]
    public function removingAMissingLabelLeavesTheLabelsAlone(): void
    {
        $this->write('DELETE', '/api/memos/1/relationships/labels', ['data' => [['type' => 'labels', 'id' => '9']]])->assertOk();

        self::assertSame(['1', ['1'], '1', ['1']], $this->stored('1'));
    }

    #[Test]
    public function anAtomicBatchNamingAMissingRowIsNotFoundAndStoresNothing(): void
    {
        $body = (string) \json_encode(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'memos', 'attributes' => ['title' => 'First']]],
            ['op' => 'add', 'data' => ['type' => 'memos', 'attributes' => ['title' => 'Second'], 'relationships' => ['labels' => ['data' => [['type' => 'labels', 'id' => '9']]]]]],
        ]]);

        $response = $this->call('POST', '/api/operations', [], [], [], ['HTTP_ACCEPT' => self::ATOMIC, 'CONTENT_TYPE' => self::ATOMIC], $body);

        $response->assertNotFound();
        self::assertSame(1, $this->storedCount('memos'));
    }

    #[Test]
    public function theDocumentOffersResourceNotFoundOnEveryWriteThatChecksARow(): void
    {
        $factory = $this->app?->make(DocumentFactory::class);
        \assert($factory instanceof DocumentFactory);
        $document = $factory->forServer('default')->toArray();

        $writes = [
            ['/stickies', 'post'],
            ['/stickies/{id}', 'patch'],
            ['/memos', 'post'],
            ['/memos/{id}', 'patch'],
            ['/memos/{id}/relationships/label', 'patch'],
            ['/memos/{id}/relationships/labels', 'patch'],
            ['/memos/{id}/relationships/labels', 'post'],
        ];
        foreach ($writes as [$path, $method]) {
            $examples = data_get($document, ['paths', $path, $method, 'responses', '404', 'content', self::MEDIA_TYPE, 'examples']);
            self::assertIsArray($examples, $method . ' ' . $path);
            self::assertArrayHasKey('RESOURCE_NOT_FOUND', $examples, $method . ' ' . $path);
        }
    }

    /**
     * @return array{0: mixed, 1: mixed, 2: mixed, 3: mixed} the memo's stored setting, settings, label and labels
     */
    private function stored(string $id): array
    {
        $providers = $this->app?->make(DataProviderRegistry::class);
        \assert($providers instanceof DataProviderRegistry);
        $memo = $providers->forType('memos')->fetchOne('memos', $id);
        self::assertTrue($memo instanceof Memo || $memo instanceof MemoRecord);

        $ids = static fn(mixed $list): mixed => \is_array($list) ? \array_map(static fn(mixed $id): string => \is_scalar($id) ? (string) $id : '', $list) : $list;
        $one = static fn(mixed $id): mixed => \is_scalar($id) ? (string) $id : $id;

        return [$one($memo->setting_id), $ids($memo->setting_ids), $one($memo->label_id), $ids($memo->label_ids)];
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertNotFound(TestResponse $response, string $message): void
    {
        self::assertSame(404, $response->getStatusCode(), $message . ': ' . $response->getContent());
        self::assertSame('404', $response->json('errors.0.status'), $message);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri): TestResponse
    {
        return $this->call('GET', $uri, [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function write(string $method, string $uri, array $body): TestResponse
    {
        return $this->json($method, $uri, $body, ['Accept' => self::MEDIA_TYPE, 'Content-Type' => self::MEDIA_TYPE]);
    }
}
