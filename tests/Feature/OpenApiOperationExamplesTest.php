<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\ExamplesInMemoryServiceProvider;

/**
 * The OpenAPI document carries the operation examples core builds from field examples, the
 * `operationExamples` and action `example` overrides replace or remove them, and
 * `openapi.examples` switches them off per server. An always-counting paginator shows its
 * total, and a relation's `exampleId()` fills linkage to a type the server does not register.
 *
 * @internal
 */
final class OpenApiOperationExamplesTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    private const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, ExamplesInMemoryServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers.legacy', ['prefix' => 'legacy-api', 'middleware' => []]);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $server = 'default'): array
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer($server)->toArray();
        \assert(\array_is_list($doc) === false);

        return $doc;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function responseExample(array $doc, string $path, string $method, string $status): mixed
    {
        $media = $this->arrayAt($doc, 'paths', $path, $method, 'responses', $status, 'content', self::MEDIA_TYPE);

        return $media['example'] ?? null;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function requestExample(array $doc, string $path, string $method): mixed
    {
        $media = $this->arrayAt($doc, 'paths', $path, $method, 'requestBody', 'content', self::MEDIA_TYPE);

        return $media['example'] ?? null;
    }

    #[Test]
    public function an_operation_gets_an_example_built_from_its_fields(): void
    {
        $doc = $this->document();

        $collection = $this->responseExample($doc, '/widgets', 'get', '200');
        self::assertIsArray($collection);
        self::assertSame('7', $this->at($collection, 'data', 0, 'id'));
        self::assertSame('Sprocket', $this->at($collection, 'data', 0, 'attributes', 'name'));
        self::assertSame(['type' => 'vendors', 'id' => '3'], $this->at($collection, 'data', 0, 'relationships', 'vendor', 'data'));

        $create = $this->requestExample($doc, '/widgets', 'post');
        self::assertIsArray($create);
        self::assertSame('Sprocket', $this->at($create, 'data', 'attributes', 'name'));
    }

    #[Test]
    public function an_override_replaces_the_parts_it_names(): void
    {
        $doc = $this->document();

        self::assertSame(['meta' => ['saved' => true]], $this->responseExample($doc, '/widgets/{id}', 'patch', '200'));
        $request = $this->requestExample($doc, '/widgets/{id}', 'patch');
        self::assertIsArray($request);
        self::assertSame('7', $this->at($request, 'data', 'id'));
    }

    #[Test]
    public function false_removes_an_operations_example(): void
    {
        self::assertNull($this->responseExample($this->document(), '/widgets/{id}', 'get', '200'));
    }

    #[Test]
    public function an_action_example_replaces_or_removes_the_generated_one(): void
    {
        $doc = $this->document();

        self::assertSame(['meta' => ['inspected' => true]], $this->responseExample($doc, '/widgets/{id}/-actions/inspect', 'post', '200'));
        self::assertNull($this->responseExample($doc, '/widgets/{id}/-actions/rename', 'post', '200'));
    }

    #[Test]
    public function a_standalone_serializer_declares_its_example_whole(): void
    {
        self::assertSame(
            ['data' => ['type' => 'gauges', 'id' => 'g1', 'attributes' => ['reading' => 42]]],
            $this->responseExample($this->document(), '/gauges/{id}', 'get', '200'),
        );
    }

    #[Test]
    public function a_missing_field_example_leaves_the_operation_without_one(): void
    {
        $projection = $this->resolve(DocumentFactory::class)->projectionForServer('legacy');

        self::assertNull($this->responseExample($this->document('legacy'), '/gizmos/{id}', 'get', '200'));
        self::assertContains(
            'fetchOne.gizmos has no example: "gizmos" declares no example for "colour".',
            \array_map(static fn(\haddowg\JsonApi\OpenApi\MissingExample $missing): string => $missing->message(), $projection->missingExamples),
        );
    }

    #[Test]
    public function an_always_counting_collection_shows_its_total(): void
    {
        $collection = $this->responseExample($this->document(), '/vendors', 'get', '200');
        self::assertIsArray($collection);

        self::assertSame(75, $this->at($collection, 'meta', 'total'));
        self::assertSame(75, $this->at($collection, 'meta', 'page', 'total'));
        self::assertSame('/api/vendors?page%5Bnumber%5D=5&page%5Bsize%5D=15', $this->at($collection, 'links', 'last', 'href'));
        self::assertStringNotContainsString('withCount', (string) \json_encode($collection));
    }

    #[Test]
    public function a_count_free_collection_shows_no_total(): void
    {
        $collection = $this->responseExample($this->document(), '/widgets', 'get', '200');
        self::assertIsArray($collection);
        $meta = $this->arrayAt($collection, 'meta');

        self::assertArrayNotHasKey('total', $meta);
        self::assertArrayNotHasKey('last', $this->arrayAt($collection, 'links'));
    }

    #[Test]
    public function a_relation_level_always_counting_paginator_shows_its_total(): void
    {
        $doc = $this->document();

        foreach (['/vendors/{id}/widgets', '/vendors/{id}/relationships/widgets'] as $path) {
            $collection = $this->responseExample($doc, $path, 'get', '200');
            self::assertIsArray($collection);

            self::assertSame(75, $this->at($collection, 'meta', 'total'), $path);
            self::assertArrayHasKey('last', $this->arrayAt($collection, 'links'), $path);
            self::assertStringNotContainsString('withCount', (string) \json_encode($collection), $path);
        }
    }

    #[Test]
    public function a_relation_example_id_fills_linkage_to_an_unregistered_type(): void
    {
        $doc = $this->document();
        $linkage = ['data' => ['type' => 'makers', 'id' => 'm1']];

        $fetched = $this->responseExample($doc, '/widgets/{id}/relationships/maker', 'get', '200');
        self::assertIsArray($fetched);
        self::assertSame($linkage['data'], $this->at($fetched, 'data'));
        self::assertSame($linkage, $this->requestExample($doc, '/widgets/{id}/relationships/maker', 'patch'));

        $collection = $this->responseExample($doc, '/widgets', 'get', '200');
        self::assertIsArray($collection);
        self::assertSame($linkage['data'], $this->at($collection, 'data', 0, 'relationships', 'maker', 'data'));
    }

    #[Test]
    public function a_fully_exampled_server_reports_no_missing_example(): void
    {
        self::assertSame([], $this->resolve(DocumentFactory::class)->projectionForServer('default')->missingExamples);
    }
}
