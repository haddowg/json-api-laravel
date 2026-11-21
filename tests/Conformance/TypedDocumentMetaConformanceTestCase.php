<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A type's documents carry the top-level meta it declares per operation: each member an
 * extractUsing() hook supplies is filled from the document's primary data, a value an
 * After hook sets wins, and a related endpoint serving the type carries its meta too. The
 * generated document narrows each declaring operation's document, and in debug mode a
 * response whose meta does not match fails loudly. Board 1 holds cards 1 to 3, card 2 done
 * and card 3 pinned. Runs on the in-memory witness ({@see InMemoryTypedDocumentMetaConformanceTest})
 * and Eloquent ({@see EloquentTypedDocumentMetaConformanceTest}).
 */
abstract class TypedDocumentMetaConformanceTestCase extends Orchestra
{
    use InteractsWithJsonApi;
    use InteractsWithOpenApiDocument;

    public const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

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
        return [JsonApiServiceProvider::class, $this->conformanceServiceProvider()];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('app.debug', false);
        $config->set('jsonapi.atomic_operations.enabled', true);
        $config->set('logging.default', 'null');
    }

    #[Test]
    public function aCollectionCarriesItsDeclaredMeta(): void
    {
        $meta = $this->metaOf($this->jsonApi()->get('/api/tm-cards'), 200);

        self::assertSame(2, $meta['open'] ?? null);
        self::assertArrayNotHasKey('note', $meta, 'a member no hook supplies is left to the handler');
    }

    #[Test]
    public function aValueAnAfterHookSetsWins(): void
    {
        $meta = $this->metaOf($this->jsonApi()->withHeader('X-Note', 'Sprint 4')->get('/api/tm-cards'), 200);

        self::assertSame('Sprint 4', $meta['note'] ?? null);
        self::assertSame(99, $meta['open'] ?? null);
    }

    #[Test]
    public function eachSingleDocumentCarriesItsOwn(): void
    {
        self::assertSame(['etag' => 'card-2-1'], $this->metaOf($this->jsonApi()->get('/api/tm-cards/2'), 200));
        self::assertSame(['etag' => 'card-1-0'], $this->metaOf($this->jsonApi()->withResource('tm-cards', '1', ['title' => 'Plan again'])->patch('/api/tm-cards/1'), 200));
        self::assertSame(['fresh' => true, 'rank' => 1], $this->metaOf($this->jsonApi()->withResource('tm-cards', null, ['title' => 'Review', 'done' => false])->post('/api/tm-cards'), 201));
    }

    #[Test]
    public function aDeleteAnswersItsMetaOnlyDocument(): void
    {
        $response = $this->jsonApi()->delete('/api/tm-cards/2');

        self::assertSame(['removed' => 'Build'], $this->metaOf($response, 200));
        self::assertArrayNotHasKey('data', $this->documentOf($response));
    }

    #[Test]
    public function anActionsHandlerDeclaresItsMeta(): void
    {
        $meta = $this->metaOf($this->jsonApi()->withHeader('Content-Type', 'application/vnd.api+json')->post('/api/tm-cards/1/-actions/pin'), 200);
        \ksort($meta);

        self::assertSame(['pinned' => 1, 'title' => 'Plan'], $meta);
    }

    #[Test]
    public function aRelatedEndpointCarriesTheRelatedTypesMeta(): void
    {
        self::assertSame(2, $this->metaOf($this->jsonApi()->get('/api/tm-boards/1/cards'), 200)['open'] ?? null);
        self::assertSame(['etag' => 'card-3-0'], $this->metaOf($this->jsonApi()->get('/api/tm-boards/1/pinned'), 200));

        $linkage = $this->documentOf($this->jsonApi()->get('/api/tm-boards/1/relationships/cards'));
        self::assertArrayNotHasKey('open', \is_array($linkage['meta'] ?? null) ? $linkage['meta'] : [], 'a relationship endpoint serves linkage only');
    }

    #[Test]
    public function aTypeDeclaringNothingCarriesNone(): void
    {
        $document = $this->documentOf($this->jsonApi()->get('/api/tm-boards'));

        self::assertSame([], \array_diff(\array_keys(\is_array($document['meta'] ?? null) ? $document['meta'] : []), ['page', 'total']));
    }

    #[Test]
    public function aComposedTypeDeclaresThroughItsSerializer(): void
    {
        self::assertSame(['labels' => 2], $this->metaOf($this->jsonApi()->get('/api/tm-labels'), 200));
    }

    #[Test]
    public function anAtomicResultCarriesTheDeclaredMeta(): void
    {
        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'tm-cards', 'attributes' => ['title' => 'Batch', 'done' => false]]],
        ]);
        $response->assertOk();

        self::assertSame(['fresh' => true, 'rank' => 1], $response->json('atomic:results.0.meta'));
    }

    #[Test]
    public function theGeneratedDocumentNarrowsEachDeclaringOperation(): void
    {
        $factory = $this->resolve(DocumentFactory::class);
        \assert($factory instanceof DocumentFactory);
        $document = \json_decode((string) \json_encode($factory->forServer('default')->toArray()), true);
        self::assertIsArray($document);

        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::TYPED_DOCUMENT_META)], $this->at($document, 'info', 'x-generator'));

        foreach ([
            ['/tm-cards', 'get', '200', 'TmCardsFetchCollectionDocument'],
            ['/tm-cards/{id}', 'get', '200', 'TmCardsFetchOneDocument'],
            ['/tm-cards/{id}', 'patch', '200', 'TmCardsUpdateDocument'],
            ['/tm-cards', 'post', '201', 'TmCardsCreateDocument'],
            ['/tm-boards/{id}/cards', 'get', '200', 'TmBoardsCardsFetchRelatedDocument'],
            ['/tm-boards/{id}/pinned', 'get', '200', 'TmBoardsPinnedFetchRelatedDocument'],
            ['/tm-labels', 'get', '200', 'TmLabelsFetchCollectionDocument'],
            ['/tm-cards/{id}', 'delete', '200', 'TmCardsDeleteDocument'],
            ['/tm-cards/{id}/-actions/pin', 'post', '200', 'TmCardsPinActionDocument'],
            ['/tm-boards', 'get', '200', 'TmBoardsCollection'],
            ['/tm-boards/{id}', 'get', '200', 'TmBoardsDocument'],
        ] as [$path, $method, $status, $component]) {
            self::assertSame(
                ['$ref' => '#/components/schemas/' . $component],
                $this->at($document, 'paths', $path, $method, 'responses', $status, 'content', 'application/vnd.api+json', 'schema'),
                $method . ' ' . $path,
            );
        }

        self::assertTrue($this->at($document, 'components', 'schemas', 'TmCardsFetchCollectionDocument', 'x-document-meta'));
        self::assertSame(['$ref' => '#/components/schemas/TmCardsCollection'], $this->at($document, 'components', 'schemas', 'TmCardsFetchCollectionDocument', 'allOf', 0));
        $meta = $this->at($document, 'components', 'schemas', 'TmCardsFetchCollectionDocument', 'allOf', 1, 'properties', 'meta');
        self::assertIsArray($meta);
        self::assertSame(['open'], $meta['required'] ?? null);
        self::assertFalse($meta['additionalProperties'] ?? null);
        $properties = $meta['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertSame(['open', 'note', 'page', 'total'], \array_keys($properties));
        $schemas = $this->at($document, 'components', 'schemas');
        self::assertIsArray($schemas);
        self::assertArrayNotHasKey('TmBoardsFetchCollectionDocument', $schemas);
    }

    #[Test]
    public function productionChecksNothing(): void
    {
        self::assertSame(['wrong'], $this->metaOf($this->jsonApi()->withResource('tm-cards', null, ['title' => 'Broken', 'done' => false])->post('/api/tm-cards'), 201)['rank'] ?? null);
        self::assertSame('two', $this->metaOf($this->jsonApi()->withHeader('X-Wrong-Type', '1')->get('/api/tm-cards'), 200)['open'] ?? null);
        self::assertTrue($this->metaOf($this->jsonApi()->withHeader('X-Bogus-Meta', '1')->get('/api/tm-cards'), 200)['bogus'] ?? null);
    }

    #[Test]
    public function debugRefusesAMetaThatDoesNotMatch(): void
    {
        config(['app.debug' => true]);

        self::assertSame(['etag' => 'card-1-0'], $this->metaOf($this->jsonApi()->get('/api/tm-cards/1'), 200));
        self::assertSame(2, $this->metaOf($this->jsonApi()->get('/api/tm-cards'), 200)['open'] ?? null);

        $wrongType = $this->jsonApi()->withHeader('X-Wrong-Type', '1')->get('/api/tm-cards');
        $wrongType->assertStatus(500);
        self::assertStringContainsString('The top-level meta of the \"tm-cards\" FetchCollection document does not match its declaration: the member \"open\" is string, not integer.', (string) $wrongType->getContent());

        $undeclared = $this->jsonApi()->withHeader('X-Bogus-Meta', '1')->get('/api/tm-cards');
        $undeclared->assertStatus(500);
        self::assertStringContainsString('the member \"bogus\" is not declared', (string) $undeclared->getContent());

        $action = $this->jsonApi()->withHeader('Content-Type', 'application/vnd.api+json')->withHeader('X-Bogus-Meta', '1')->post('/api/tm-cards/1/-actions/pin');
        $action->assertStatus(500);
        self::assertStringContainsString('The top-level meta of the \"pin\" action of type \"tm-cards\" does not match its declaration: the member \"bogus\" is not declared.', (string) $action->getContent());

        self::assertSame(['removed' => 'Build'], $this->metaOf($this->jsonApi()->delete('/api/tm-cards/2'), 200));
    }

    #[Test]
    public function debugChecksEachAtomicResult(): void
    {
        config(['app.debug' => true]);
        $batch = static fn(string $title): array => [
            ['op' => 'add', 'data' => ['type' => 'tm-cards', 'attributes' => ['title' => $title, 'done' => false]]],
        ];

        $this->atomic($batch('Fine'))->assertOk();

        $broken = $this->atomic($batch('Broken'));
        $broken->assertStatus(500);
        self::assertStringContainsString('the member \"rank\" is array, not integer', (string) $broken->getContent());
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
        ], (string) \json_encode(['atomic:operations' => $operations]));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<array-key, mixed>
     */
    private function documentOf(TestResponse $response): array
    {
        $document = \json_decode((string) $response->getContent(), true);
        self::assertIsArray($document);

        return $document;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<array-key, mixed>
     */
    private function metaOf(TestResponse $response, int $status): array
    {
        $response->assertStatus($status);
        $meta = $this->documentOf($response)['meta'] ?? [];
        self::assertIsArray($meta);
        unset($meta['page'], $meta['total']);

        return $meta;
    }
}
