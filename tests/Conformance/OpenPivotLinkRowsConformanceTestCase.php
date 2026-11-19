<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\BoardItem;

/**
 * An open pivot's extra members reach its entry's fill hook on every link row a write
 * touches: through the rows a `MorphToMany` declared with `identifiedByRows()` writes, and
 * through the rows an `identifiedBy()` closure relation's own `fillUsing()` writes when the
 * entry is all it declares. The `tag-boards` keep each tag in the row's label.
 */
abstract class OpenPivotLinkRowsConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

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
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('link_rows.tags', true);

        return [JsonApiServiceProvider::class, $this->conformanceServiceProvider()];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.base_uri', 'http://localhost/api');
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    public function aDeclaredLinkRowTakesItsExtraMembersThroughTheEntry(): void
    {
        $response = $this->write('PATCH', '/api/tag-boards/1/relationships/tagged', ['data' => [
            ['type' => 'notes', 'id' => '1', 'meta' => ['pivot' => ['tag' => 'pinned']]],
            ['type' => 'photos', 'id' => '1', 'meta' => ['pivot' => ['position' => 0]]],
            ['type' => 'notes', 'id' => '3', 'meta' => ['pivot' => ['position' => 4, 'tag' => 'fresh']]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // An existing row sending only a tag is still written, one sending none keeps its label.
        self::assertSame(['notes:1' => 'pinned', 'photos:1' => 'trip', 'notes:3' => 'fresh'], $this->labels('1'));

        $wrong = $this->write('POST', '/api/tag-boards/1/relationships/tagged', ['data' => [
            ['type' => 'photos', 'id' => '2', 'meta' => ['pivot' => ['tag' => 5]]],
        ]]);
        self::assertSame(422, $wrong->getStatusCode(), (string) $wrong->getContent());
        self::assertSame('/data/0/meta/pivot/tag', $wrong->json('errors.0.source.pointer'));
    }

    #[Test]
    public function aClosureRelationDeclaringOnlyTheEntryFillsTheRowsItsHookWrites(): void
    {
        $response = $this->write('PATCH', '/api/tag-boards/2/relationships/looseTagged', ['data' => [
            ['type' => 'notes', 'id' => '3', 'meta' => ['pivot' => ['tag' => 'loose']]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['notes:3' => 'loose'], $this->labels('2'));
    }

    /**
     * Each link row's label on board `$id`, read through the data layer, keyed by member.
     *
     * @return array<string, string|null>
     */
    private function labels(string $id): array
    {
        $providers = $this->app?->make(DataProviderRegistry::class);
        self::assertInstanceOf(DataProviderRegistry::class, $providers);
        $board = $providers->forType('tag-boards')->fetchOne('tag-boards', $id);
        self::assertNotNull($board);
        $rows = Accessor::get($board, 'boardItems');
        self::assertIsIterable($rows);

        $labels = [];
        foreach ($rows as $row) {
            self::assertInstanceOf(BoardItem::class, $row);
            $labels[$row->item_type . ':' . $row->item_id] = $row->label;
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function write(string $method, string $uri, array $document): TestResponse
    {
        return $this->json($method, $uri, $document, ['Accept' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE]);
    }
}
