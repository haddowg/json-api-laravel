<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\ModelMapServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\Pressing;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\VinylRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The three-tier resource→model resolution, end-to-end over HTTP (ADR 0019):
 *
 *  - `recordings` is served through its `#[AsJsonApiResource(model: VinylRecord::class)]`
 *    declaration (the type name diverges from the model — convention could not map it);
 *  - `pressings` is served through the convention guess alone (no attribute, no wiring);
 *  - `imports` proves the explicit tier shadows: it is convention-mappable, yet the
 *    fixture provider's in-memory registration at the default priority `0` wins over the
 *    `-256` auto pair (whose model has no table, so a leak would error, not pass);
 *  - `ghosts` proves the auto pair claims ONLY resolved types: with no tier claiming it,
 *    a request fails with the same no-provider `LogicException` (rendered as a JSON:API
 *    `500`) an unservable type raised before the tiers existed.
 *
 * The `jsonapi:optimize` snapshot half lives in {@see ModelMapOptimizeTest} (whose app
 * omits the unservable `ghosts` type, which servability validation rightly fails).
 *
 * @internal
 */
#[CoversNothing]
final class ModelMappingTiersTest extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    public function test_a_declared_model_serves_the_type_whose_name_diverges_from_convention(): void
    {
        VinylRecord::query()->create(['title' => 'Loveless']);

        $response = $this->get('/api/recordings', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.type', 'recordings');
        $response->assertJsonPath('data.0.attributes.title', 'Loveless');
    }

    public function test_a_declared_model_backs_writes_through_the_auto_registered_persister(): void
    {
        $response = $this->json('POST', '/api/recordings', [
            'data' => ['type' => 'recordings', 'attributes' => ['title' => 'Isn\'t Anything']],
        ], ['Accept' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE]);

        $response->assertCreated();
        self::assertSame(1, VinylRecord::query()->where('title', "Isn't Anything")->count());
    }

    public function test_the_convention_guess_serves_a_type_with_no_attribute_and_no_wiring(): void
    {
        Pressing::query()->create(['title' => 'First pressing']);

        $response = $this->get('/api/pressings/1', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonPath('data.type', 'pressings');
        $response->assertJsonPath('data.attributes.title', 'First pressing');
    }

    public function test_an_explicit_registration_shadows_the_auto_pair_for_a_convention_mappable_type(): void
    {
        // `imports` has a convention model (Import) — but that model has NO table, so
        // this response can only have come from the explicit in-memory registration.
        $response = $this->get('/api/imports/1', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonPath('data.type', 'imports');
        $response->assertJsonPath('data.attributes.title', ModelMapServiceProvider::SHADOW_TITLE);
    }

    public function test_an_unresolvable_type_keeps_failing_with_the_no_provider_wiring_error(): void
    {
        // Exactly the pre-tier behaviour: no tier claims `ghosts`, so the registry's
        // LogicException ('No JSON:API data provider is registered for type "ghosts".')
        // surfaces as the exception renderer's JSON:API 500 — never a silent claim by
        // the auto pair.
        $response = $this->get('/api/ghosts', ['Accept' => self::MEDIA_TYPE]);

        $response->assertStatus(500);
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);
        $response->assertJsonPath('errors.0.status', '500');
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
            ModelMapServiceProvider::class,
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

        $config->set('jsonapi.discovery.paths', [
            \dirname(__DIR__) . '/Fixtures/ModelMap/JsonApi',
            \dirname(__DIR__) . '/Fixtures/ModelMap/Unservable',
        ]);
        $config->set('jsonapi.eloquent.model_namespace', 'haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('vinyl_records', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        Schema::create('pressings', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        // Deliberately NO `imports` table — see the shadowing test above.
    }
}
