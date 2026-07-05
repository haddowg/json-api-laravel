<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Models\Product;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\App\MusicCatalog\Support\ProductIdCodec;
use Workbench\Database\Seeders\McCatalogSeeder;

/**
 * The custom resource-id encoding witness on the reference Eloquent layer (ADR 0014) —
 * the Laravel twin of the bundle's `DoctrineEncodedIdTest` / the Symfony example's
 * `EncodedIdTest`: the `products` type keys its row by a database-generated integer that
 * never reaches the wire — the JSON:API `id` (and the URL) is an opaque `prod-…` token the
 * {@see ProductIdCodec} encodes/decodes. It proves the encode/decode round-trip end to end:
 *
 *  - a read renders the encoded wire id, and GET by it decodes to the storage key and
 *    finds the row (the provider's `fetchOne` decode);
 *  - the route `{id}` is constrained to the codec token (`matchAs()`), so a malformed id
 *    404s at routing before any handler runs, while a well-formed-but-unknown token
 *    reaches the handler and renders a JSON:API 404 (decode succeeds; no row holds it);
 *  - update and delete targets resolve through the same decode (the write arms fetch
 *    their target via `fetchOne`);
 *  - a relationship write whose linkage carries an encoded token decodes it (keyed by
 *    the related type) before the FK write — both on the relationship endpoint and
 *    embedded in a whole-resource write — and an undecodable linkage token is a clean
 *    404, never a raw wire string keyed into the integer FK (a 500);
 *  - a store-provided create renders the freshly assigned key as the encoded token;
 *  - the `?include` batch keys parents by their ENCODED wire id, so an encoded parent's
 *    linkage matches the serializer's `getId()`.
 *
 * Eloquent-only by design: encoding is a reference-layer concern and the in-memory
 * witness has no decode (wire == storage there), mirroring the bundle's posture where
 * the encoded type is exercised on the Doctrine kernel only (ADR 0014).
 *
 * @internal
 */
#[CoversNothing]
final class EloquentEncodedIdTest extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @var array<int, string> storage int id => wire token, captured at seed time
     */
    private array $wireIds = [];

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            MusicCatalogEloquentServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'admin' => ['prefix' => 'admin', 'middleware' => [], 'domain' => null],
        ]);
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\dirname(__DIR__, 3) . '/workbench/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new McCatalogSeeder())->run();

        // Seeded products: 1 'Vinyl Reissue Box' (parent null), 2 'Deluxe Vinyl Reissue'
        // (parent 1) — see Fixtures::products().
        $codec = new ProductIdCodec();
        $this->wireIds = [1 => $codec->encode('1'), 2 => $codec->encode('2')];
    }

    public function test_a_read_renders_the_encoded_wire_id_and_get_by_it_decodes_to_the_storage_key(): void
    {
        $wire = $this->wireIds[1];
        self::assertStringStartsWith('prod-', $wire);
        self::assertNotSame('1', $wire, 'the wire id is the encoded token, not the integer storage key');

        $this->readJsonApi('/api/products/' . $wire)
            ->assertOk()
            ->assertJsonPath('data.id', $wire)
            ->assertJsonPath('data.attributes.name', 'Vinyl Reissue Box');
    }

    public function test_the_route_id_segment_is_constrained_so_a_malformed_id_404s_at_routing(): void
    {
        // The author's declared id pattern (matchAs('prod-[0-9a-f]+')) is composed into
        // the show route's `{id}` requirement, so a bare integer never matches — the 404
        // happens at ROUTING, before any JSON:API handler runs.
        $show = Route::getRoutes()->getByName('jsonapi.products.show');
        self::assertNotNull($show);
        self::assertStringContainsString('prod-[0-9a-f]+', $show->wheres['id'] ?? '');

        $this->readJsonApi('/api/products/999')->assertNotFound();

        // A well-formed-but-unknown token, by contrast, MATCHES the route and reaches the
        // handler, which 404s as a JSON:API error document (the decode succeeds; no row
        // holds that key) — proving the malformed id above was a routing rejection.
        $miss = $this->readJsonApi('/api/products/' . (new ProductIdCodec())->encode('424242'));
        $miss->assertNotFound();
        self::assertStringContainsString(self::MEDIA_TYPE, (string) $miss->headers->get('Content-Type'));
    }

    public function test_update_and_delete_targets_resolve_through_the_wire_token(): void
    {
        $wire = $this->wireIds[2];

        // The update target is fetched by the wire token (fetchOne decodes it).
        $this->writeJsonApi('PATCH', '/api/products/' . $wire, [
            'data' => ['type' => 'products', 'id' => $wire, 'attributes' => ['name' => 'Deluxe Vinyl Reissue (2xLP)']],
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $wire)
            ->assertJsonPath('data.attributes.name', 'Deluxe Vinyl Reissue (2xLP)');

        self::assertSame('Deluxe Vinyl Reissue (2xLP)', Product::query()->findOrFail(2)->name);

        // So is the delete target: the row persisted under integer 2 is gone afterwards.
        $this->deleteJsonApi('/api/products/' . $wire)->assertStatus(204);
        self::assertNull(Product::query()->find(2));
    }

    public function test_a_relationship_write_decodes_an_encoded_linkage_id(): void
    {
        // Set product 1's `parent` to product 2 via the relationship endpoint, supplying
        // the ENCODED token. The persister decodes the linkage id (keyed by the related
        // type, `products`) to integer 2 before the FK write; the rendered linkage
        // re-encodes it.
        $response = $this->writeJsonApi('PATCH', '/api/products/' . $this->wireIds[1] . '/relationships/parent', [
            'data' => ['type' => 'products', 'id' => $this->wireIds[2]],
        ]);
        $response->assertOk();
        self::assertSame(['type' => 'products', 'id' => $this->wireIds[2]], $response->json('data'));

        self::assertSame(2, Product::query()->findOrFail(1)->parent_id, 'the FK holds the decoded storage key');

        // The related endpoint resolves the new parent, rendered with its wire id.
        $this->readJsonApi('/api/products/' . $this->wireIds[1] . '/parent')
            ->assertOk()
            ->assertJsonPath('data.id', $this->wireIds[2])
            ->assertJsonPath('data.attributes.name', 'Deluxe Vinyl Reissue');
    }

    public function test_a_relationship_write_with_an_undecodable_linkage_id_is_a_404_not_a_500(): void
    {
        // A structurally-plausible but undecodable token ('prod-zz' — not hex) must NOT
        // reach the FK as a raw wire string (a TypeError/SQL error → 500); the persister's
        // decode raises a clean JSON:API 404 instead.
        $response = $this->writeJsonApi('PATCH', '/api/products/' . $this->wireIds[2] . '/relationships/parent', [
            'data' => ['type' => 'products', 'id' => 'prod-zz'],
        ]);

        $response->assertNotFound();
        self::assertStringContainsString(self::MEDIA_TYPE, (string) $response->headers->get('Content-Type'));

        // The write was refused outright: product 2 keeps its seeded parent.
        self::assertSame(1, Product::query()->findOrFail(2)->parent_id);
    }

    public function test_an_embedded_to_one_linkage_with_an_encoded_id_resolves_on_create(): void
    {
        // The linkage decode also covers a relationship embedded in a whole-resource
        // write: the created row's FK holds the DECODED storage key of the parent.
        $created = $this->writeJsonApi('POST', '/api/products', [
            'data' => [
                'type' => 'products',
                'attributes' => ['name' => 'Slipmat'],
                'relationships' => ['parent' => ['data' => ['type' => 'products', 'id' => $this->wireIds[1]]]],
            ],
        ]);
        $created->assertStatus(201);

        $wire = $created->json('data.id');
        self::assertIsString($wire);
        self::assertStringStartsWith('prod-', $wire, 'the assigned id renders as the encoded wire token');

        $storageKey = (new ProductIdCodec())->decode($wire);
        self::assertIsString($storageKey);
        $row = Product::query()->findOrFail($storageKey);
        self::assertSame('Slipmat', $row->name);
        self::assertSame(1, $row->parent_id, 'the embedded linkage decoded to the parent storage key');

        // The Location targets the encoded token, and GET by it finds the new row.
        self::assertStringEndsWith('/products/' . $wire, (string) $created->headers->get('Location'));
        $this->readJsonApi('/api/products/' . $wire)
            ->assertOk()
            ->assertJsonPath('data.attributes.name', 'Slipmat');
    }

    public function test_the_include_batch_keys_an_encoded_parent_by_its_wire_id(): void
    {
        // GET /products?include=parent over page 2 (size 1): the primary data is product 2
        // ALONE, so its parent (product 1) is off-page and MUST ride in `included[]`. The
        // include orchestrator reconciles each batch entry back to its parent by the
        // serializer's getId() — the ENCODED token — so the provider's batch map must be
        // wire-keyed too: a storage-keyed map would miss every parent and drop the include.
        $response = $this->readJsonApi('/api/products?include=parent&page[number]=2&page[size]=1');
        $response->assertOk();

        /** @var list<array<string, mixed>> $data */
        $data = (array) $response->json('data');
        self::assertCount(1, $data);
        self::assertSame($this->wireIds[2], $data[0]['id'] ?? null, 'page 2 holds product 2 alone');
        self::assertSame(
            ['type' => 'products', 'id' => $this->wireIds[1]],
            \data_get($data[0], 'relationships.parent.data'),
            "product 2's parent linkage carries the encoded token",
        );

        /** @var list<array<string, mixed>> $included */
        $included = (array) $response->json('included');
        self::assertContains($this->wireIds[1], \array_column($included, 'id'), 'the encoded off-page parent rides in included[]');
    }

    public function test_an_encoder_less_type_on_the_same_provider_renders_wire_equals_storage(): void
    {
        // `artists` declares no encoder, so the SAME Eloquent provider treats the wire id
        // as the storage key directly — untouched by the decode path.
        $this->readJsonApi('/api/artists/1')
            ->assertOk()
            ->assertJsonPath('data.id', '1');
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
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function deleteJsonApi(string $uri): TestResponse
    {
        return $this->call('DELETE', $uri, [], [], [], $this->transformHeadersToServerVars([
            'Accept' => self::MEDIA_TYPE,
        ]));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function readJsonApi(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }
}
