<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\GettingStarted\Models\Album;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The [getting-started](../../docs/getting-started.md) flow, run verbatim (ADR 0019):
 * ONE resource class with fields only (no attribute, no `model:`, no provider/persister
 * wiring anywhere) plus ONE plain Eloquent model whose name matches the convention — and
 * all five CRUD endpoints work over HTTP, backed by the auto-registered reference
 * Eloquent pair. The only configuration is the documented pair the page assumes: the
 * discovery path (default `app/JsonApi`) and the convention namespace (default
 * `App\Models`), each pointed at the fixture twin here.
 *
 * No workbench wiring provider is booted — the package provider alone must deliver the
 * documented zero-to-endpoint promise.
 *
 * @internal
 */
#[CoversNothing]
final class GettingStartedTest extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    public function test_get_collection_serves_the_documented_response_shape(): void
    {
        $this->seedAlbum('OK Computer');

        $response = $this->get('/api/albums', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        // The documented response: jsonapi.version, data[].type/id/attributes/links.self.
        $response->assertJsonPath('jsonapi.version', '1.1');
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.type', 'albums');
        $response->assertJsonPath('data.0.id', '1');
        $response->assertJsonPath('data.0.attributes.title', 'OK Computer');
        self::assertIsString($response->json('data.0.links.self'));
        // The prefixed absolute form on the page ('http://localhost/api/albums/1') needs
        // the documented `base_uri` setting (see configuration.md); with the bare default
        // the link derives from scheme + host, which is not this test's concern.
        self::assertStringEndsWith('/albums/1', $response->json('data.0.links.self'));
    }

    public function test_get_one_reads_through_the_convention_mapped_model(): void
    {
        $this->seedAlbum('OK Computer');

        $response = $this->get('/api/albums/1', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonPath('data.type', 'albums');
        $response->assertJsonPath('data.attributes.title', 'OK Computer');
    }

    public function test_post_creates_a_row_with_no_persister_wired_by_hand(): void
    {
        $response = $this->writeJsonApi('POST', '/api/albums', [
            'data' => ['type' => 'albums', 'attributes' => ['title' => 'In Rainbows']],
        ]);

        $response->assertCreated();
        $response->assertHeader('Location');
        $response->assertJsonPath('data.attributes.title', 'In Rainbows');

        self::assertSame(1, Album::query()->where('title', 'In Rainbows')->count());
    }

    public function test_patch_updates_the_row(): void
    {
        $this->seedAlbum('OK Computer');

        $response = $this->writeJsonApi('PATCH', '/api/albums/1', [
            'data' => ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => 'Kid A']],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.title', 'Kid A');

        self::assertSame('Kid A', Album::query()->findOrFail(1)->title);
    }

    public function test_delete_removes_the_row(): void
    {
        $this->seedAlbum('OK Computer');

        $response = $this->json('DELETE', '/api/albums/1', [], ['Accept' => self::MEDIA_TYPE]);

        $response->assertNoContent();
        self::assertSame(0, Album::query()->count());
    }

    public function test_the_declared_constraints_validate_a_write_as_documented(): void
    {
        // Step 3 of the page: `required()` means a POST without a title is a 422 with
        // the documented source pointer.
        $response = $this->writeJsonApi('POST', '/api/albums', [
            'data' => ['type' => 'albums', 'attributes' => []],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.status', '422');
        $response->assertJsonPath('errors.0.source.pointer', '/data/attributes/title');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // The documented flow's two conventions, pointed at the fixture twins of
        // `app/JsonApi` and `App\Models`.
        $config->set('jsonapi.discovery.paths', [\dirname(__DIR__) . '/Fixtures/GettingStarted/JsonApi']);
        $config->set('jsonapi.eloquent.model_namespace', 'haddowg\JsonApiLaravel\Tests\Fixtures\GettingStarted\Models');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('albums', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamp('released_at')->nullable();
            $table->boolean('explicit')->default(false);
            $table->timestamps();
        });
    }

    private function seedAlbum(string $title): void
    {
        $album = new Album();
        $album->title = $title;
        $album->save();
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
}
