<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\LoadedLinkage;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Event\AfterFetchOneEvent;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use haddowg\JsonApiLaravel\Tests\Fixtures\LoadedLinkage\Binding;
use haddowg\JsonApiLaravel\Tests\Fixtures\LoadedLinkage\BindingRecord;
use haddowg\JsonApiLaravel\Tests\Fixtures\LoadedLinkage\LoadedLinkageServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

        // A read of a binding answers with its template, a resource the read itself never
        // loaded relations for.
        Event::listen(AfterFetchOneEvent::class, function (AfterFetchOneEvent $event): void {
            $binding = $event->entity;
            $template = match (true) {
                $binding instanceof Binding => $binding->template,
                $binding instanceof BindingRecord => $binding->template,
                default => null,
            };
            if ($event->type === 'bindings' && $template !== null) {
                $event->setResponse(DataResponse::fromResource($template, app(ServerRegistry::class)->get()->serializerFor('templates')));
            }
        });

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

    #[Test]
    public function anActionsResourceRendersTheLinkageItsRelationshipEndpointServes(): void
    {
        foreach (['/templates/1/-actions/show', '/templates/-actions/list'] as $action) {
            $response = $this->action($action);

            foreach (['bindings', 'sharedBindings'] as $name) {
                $relationship = $this->templateValue($response, 'relationships.' . $name);
                self::assertIsArray($relationship, $action . ' ' . $name);
                if (\array_key_exists('data', $relationship)) {
                    self::assertSame(['1'], $this->ids($relationship['data']), $action . ' ' . $name);
                }
            }
            self::assertSame(
                $this->ids($this->read('/templates/1/relationships/listedBindings')->json('data')),
                $this->ids($this->templateValue($response, 'relationships.listedBindings.data')),
                $action,
            );
            $this->assertNoHiddenBinding($response);
        }
    }

    #[Test]
    public function anActionsIncludedToManyIsScopedAndFiltered(): void
    {
        foreach (['/templates/1/-actions/show', '/templates/-actions/list'] as $action) {
            foreach (['bindings', 'sharedBindings', 'listedBindings'] as $name) {
                $response = $this->action($action . '?include=' . $name);

                self::assertSame(['1'], $this->ids($this->templateValue($response, 'relationships.' . $name . '.data')), $action . ' ' . $name);
                self::assertSame(['1'], $this->ids($response->json('included')), $action . ' ' . $name);
                $this->assertNoHiddenBinding($response);
            }
        }
    }

    #[Test]
    public function anActionsNestedIncludeIsScopedAndFiltered(): void
    {
        foreach (['bindings', 'sharedBindings', 'listedBindings'] as $name) {
            $response = $this->action('/bindings/1/-actions/show?include=template.' . $name);

            $templates = \array_values(\array_filter(
                (array) $response->json('included'),
                static fn(mixed $resource): bool => \is_array($resource) && ($resource['type'] ?? null) === 'templates',
            ));
            self::assertCount(1, $templates, $name);
            self::assertSame(['1'], $this->ids(data_get($templates[0], 'relationships.' . $name . '.data')), $name);
            $this->assertNoHiddenBinding($response);
        }
    }

    #[Test]
    public function anActionsCountAgreesWithTheRelationshipEndpoint(): void
    {
        foreach (['/templates/1/-actions/show', '/templates/-actions/list'] as $action) {
            $response = $this->action($action . '?withCount=bindings', self::MEDIA_TYPE . ';profile="' . self::COUNTABLE_PROFILE . '"');

            self::assertSame(1, $this->templateValue($response, 'relationships.bindings.meta.total'), $action);
            $this->assertNoHiddenBinding($response);
        }
    }

    #[Test]
    public function aMixedTypeActionResponseCountsEachTypesRelations(): void
    {
        // Template 1 and binding 1 share a wire id, so the count must follow the object.
        $response = $this->action('/templates/-actions/mixed?withCount=bindings', self::MEDIA_TYPE . ';profile="' . self::COUNTABLE_PROFILE . '"');

        self::assertSame(['templates', 'bindings'], \array_column((array) $response->json('data'), 'type'));
        self::assertSame(1, $response->json('data.0.relationships.bindings.meta.total'));
        self::assertNull($response->json('data.1.relationships.bindings'));
        $this->assertNoHiddenBinding($response);
    }

    #[Test]
    public function aMixedTypeActionResponseRefusesANameNoMemberTypeCounts(): void
    {
        $response = $this->call('POST', '/api/templates/-actions/mixed?withCount=template', server: [
            'HTTP_ACCEPT' => self::MEDIA_TYPE . ';profile="' . self::COUNTABLE_PROFILE . '"',
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ]);

        $response->assertStatus(400);
        self::assertSame('RELATIONSHIP_COUNT_NOT_ALLOWED', $response->json('errors.0.code'));
    }

    #[Test]
    public function aResponseAHookSwapsInIsScopedAndFiltered(): void
    {
        $response = $this->read('/bindings/1');

        self::assertSame('templates', $response->json('data.type'));
        self::assertSame(['1'], $this->ids($response->json('data.relationships.listedBindings.data')));
        $this->assertNoHiddenBinding($response);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function action(string $path, string $accept = self::MEDIA_TYPE): TestResponse
    {
        $response = $this->call('POST', '/api' . $path, server: ['HTTP_ACCEPT' => $accept, 'CONTENT_TYPE' => self::MEDIA_TYPE]);
        $response->assertOk();

        return $response;
    }

    /**
     * A value under template 1 as the response's primary data renders it, alone or in a
     * collection.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function templateValue(TestResponse $response, string $key): mixed
    {
        $data = $response->json('data');
        self::assertIsArray($data);
        $template = \array_is_list($data) ? ($data[0] ?? null) : $data;
        self::assertIsArray($template);
        self::assertSame('1', $template['id'] ?? null);

        return data_get($template, $key);
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
