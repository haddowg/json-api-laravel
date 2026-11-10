<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\LoadedLinkage;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\LoadedLinkage\LoadedLinkageServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A to-many's linkage holds what its relationship endpoint serves, even when something
 * else read the relation off the model first. Template 1's `summary` attribute reads its
 * bindings, which on Eloquent loads both relations before the relationships render.
 * Binding 2 is hidden by the bindings type's own provider and binding 3 by its default
 * filter, so only binding 1 may appear.
 */
abstract class LoadedLinkageTestCase extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string COUNTABLE_PROFILE = 'https://haddowg.github.io/json-api/profiles/countable/';

    abstract protected function store(): string;

    protected function getPackageProviders($app): array
    {
        $app['config']->set('loaded_linkage.store', $this->store());

        return [JsonApiServiceProvider::class, LoadedLinkageServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
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
        if ($this->store() !== 'eloquent') {
            return;
        }

        Schema::create('ll_templates', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('ll_bindings', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('template_id')->nullable();
            $table->string('name');
            $table->boolean('hidden')->default(false);
            $table->boolean('detached')->default(false);
        });
        Schema::create('ll_template_shared', static function (Blueprint $table): void {
            $table->unsignedInteger('template_id');
            $table->unsignedInteger('binding_id');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->store() !== 'eloquent') {
            return;
        }

        DB::table('ll_templates')->insert(['id' => 1, 'name' => 'Template One']);
        DB::table('ll_bindings')->insert([
            ['id' => 1, 'template_id' => 1, 'name' => 'Visible Binding', 'hidden' => false, 'detached' => false],
            ['id' => 2, 'template_id' => 1, 'name' => 'Scoped Out Binding', 'hidden' => true, 'detached' => false],
            ['id' => 3, 'template_id' => 1, 'name' => 'Detached Binding', 'hidden' => false, 'detached' => true],
        ]);
        DB::table('ll_template_shared')->insert([
            ['template_id' => 1, 'binding_id' => 1],
            ['template_id' => 1, 'binding_id' => 2],
            ['template_id' => 1, 'binding_id' => 3],
        ]);
    }

    #[Test]
    public function aLazyToManyReadByAnAttributeRendersNoUnscopedLinkage(): void
    {
        $response = $this->read('/templates/1');

        foreach (['bindings', 'sharedBindings'] as $name) {
            $relationship = $response->json('data.relationships.' . $name);
            self::assertIsArray($relationship, $name);
            if (\array_key_exists('data', $relationship)) {
                self::assertSame(['1'], $this->ids($relationship['data']), $name);
            }
        }
        $this->assertNoHiddenBinding($response);
    }

    #[Test]
    public function alwaysEmittedLinkageIsScopedAndFiltered(): void
    {
        $response = $this->read('/templates/1');

        self::assertSame(['1'], $this->ids($response->json('data.relationships.listedBindings.data')));
        $this->assertNoHiddenBinding($response);
    }

    #[Test]
    public function anIncludedToManyIsScopedAndFiltered(): void
    {
        foreach (['bindings', 'sharedBindings', 'listedBindings'] as $name) {
            $response = $this->read('/templates/1?include=' . $name);

            self::assertSame(['1'], $this->ids($response->json('data.relationships.' . $name . '.data')), $name);
            self::assertSame(['1'], $this->ids($response->json('included')), $name);
            $this->assertNoHiddenBinding($response);
        }
    }

    #[Test]
    public function theRelationshipEndpointAndTheCountAgreeWithTheLinkage(): void
    {
        foreach (['bindings', 'sharedBindings', 'listedBindings'] as $name) {
            self::assertSame(['1'], $this->ids($this->read('/templates/1/relationships/' . $name)->json('data')), $name);
        }

        $response = $this->read('/templates/1?withCount=bindings', self::MEDIA_TYPE . ';profile="' . self::COUNTABLE_PROFILE . '"');
        self::assertSame(1, $response->json('data.relationships.bindings.meta.total'));
        $this->assertNoHiddenBinding($response);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function read(string $path, string $accept = self::MEDIA_TYPE): TestResponse
    {
        $response = $this->get('/api' . $path, ['Accept' => $accept]);
        $response->assertOk();

        return $response;
    }

    /**
     * @return list<string>
     */
    protected function ids(mixed $identifiers): array
    {
        self::assertIsArray($identifiers);

        $ids = [];
        foreach ($identifiers as $identifier) {
            self::assertIsArray($identifier);
            self::assertIsString($identifier['id'] ?? null);
            $ids[] = $identifier['id'];
        }

        return $ids;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function assertNoHiddenBinding(TestResponse $response): void
    {
        $json = (string) $response->getContent();
        self::assertStringNotContainsString('"id":"2"', $json);
        self::assertStringNotContainsString('"id":"3"', $json);
    }
}
