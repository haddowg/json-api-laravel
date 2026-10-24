<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\OpenApiServe;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\OpenApi\DocumentWarmer;
use haddowg\JsonApiLaravel\Tests\Feature\TestCase;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\FeatureGateDecorator;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\FeatureGateSchemaDecorator;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\FeatureMarkingDecorator;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\UnkeyedDecorator;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\UnkeyedSchemaDecorator;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Validator;

/**
 * Shared scaffolding for the serve-time customisation suites (ADR 0036): each subclass
 * names the serve decorators it tags, requests go to a chosen host, and the warmer can be
 * run on demand.
 *
 * Every suite configures `cache_control`, exposes the docs outside debug (so a lazy build
 * is never written back), runs with an array session driver, and adds a `web`-group probe
 * route, so the no-cookie guarantee is tested where a cookie can appear.
 *
 * @internal
 */
abstract class OpenApiServeTestCase extends TestCase
{
    use InteractsWithOpenApiDocument;

    public const string CACHE_CONTROL = 'public, max-age=60, s-maxage=300, stale-while-revalidate=86400';

    private const string OAS_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema/2022-10-07';

    protected string $cacheDir = '';

    protected string $publicDir = '';

    /**
     * The serve decorators to tag, in the order they run.
     *
     * @return array{openapi: list<class-string>, schemas: list<class-string>}
     */
    abstract protected static function serveDecorators(): array;

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $this->cacheDir = \sys_get_temp_dir() . '/jsonapi-serve-' . \uniqid();
        $this->publicDir = $this->cacheDir . '-public';

        $config = $app->make(Repository::class);
        \assert($config instanceof Repository);
        $config->set('app.debug', false);
        $config->set('app.key', 'base64:' . \base64_encode(\str_repeat('k', 32)));
        $config->set('session.driver', 'array');
        $config->set('jsonapi.openapi.expose_in_prod', true);
        $config->set('jsonapi.openapi.cache_control', self::CACHE_CONTROL);
        $config->set('jsonapi.openapi.cache_path', $this->cacheDir);
        $config->set('jsonapi.openapi.public_path', $this->publicDir);
        $config->set('jsonapi.openapi.tags', [['name' => 'Catalog', 'description' => 'Configured, used by no operation']]);

        $app->tag([FeatureMarkingDecorator::class], JsonApiServiceProvider::OPENAPI_FACTORY_TAG);

        $decorators = static::serveDecorators();
        if ($decorators['openapi'] !== []) {
            $app->tag($decorators['openapi'], JsonApiServiceProvider::OPENAPI_SERVE_DECORATOR_TAG);
        }
        if ($decorators['schemas'] !== []) {
            $app->tag($decorators['schemas'], JsonApiServiceProvider::JSON_SCHEMA_SERVE_DECORATOR_TAG);
        }
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/session-probe', static fn(): string => 'ok');
    }

    protected function setUp(): void
    {
        parent::setUp();

        FeatureGateDecorator::$decorated = 0;
        FeatureGateDecorator::$keyed = 0;
        FeatureGateSchemaDecorator::$decorated = 0;
        UnkeyedDecorator::$decorated = 0;
        UnkeyedSchemaDecorator::$decorated = 0;
    }

    protected function tearDown(): void
    {
        $this->resolve(ArtifactStore::class)->clear();
        @\rmdir($this->cacheDir);
        foreach (\glob($this->publicDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->publicDir);

        parent::tearDown();
    }

    /**
     * @param array<string, string> $headers request headers, e.g. `['If-None-Match' => '"abc"']`
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function fetch(string $path, string $host = 'tenant.test', array $headers = []): TestResponse
    {
        return $this->get('http://' . $host . $path, $headers);
    }

    protected function warm(): void
    {
        $this->assertSame([], $this->resolve(DocumentWarmer::class)->warm());
    }

    protected function store(): ArtifactStore
    {
        return $this->resolve(ArtifactStore::class);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function etagOf(TestResponse $response): string
    {
        $etag = $response->baseResponse->getEtag();
        $this->assertIsString($etag);

        return $etag;
    }

    protected static function quoted(string $hash): string
    {
        return '"' . $hash . '"';
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, mixed>
     */
    protected function document(TestResponse $response): array
    {
        $decoded = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function arr(mixed $value): array
    {
        $this->assertIsArray($value);

        return $value;
    }

    /**
     * The response's `Cache-Control`, compared with {@see self::CACHE_CONTROL} as a set of
     * directives because Symfony normalises their order.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function cacheControl(TestResponse $response): ?string
    {
        $value = $response->baseResponse->headers->get('Cache-Control');
        if ($value === null) {
            return null;
        }
        $directives = \array_map('trim', \explode(',', $value));
        $expected = \array_map('trim', \explode(',', self::CACHE_CONTROL));
        \sort($directives);
        \sort($expected);

        return $directives === $expected ? self::CACHE_CONTROL : $value;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function assertValidOpenApi(TestResponse $response): void
    {
        // The object decode keeps an empty schema `{}` as an object.
        $document = \json_decode((string) $response->getContent(), false, 512, \JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $document);

        $this->assertTrue(
            $this->metaValidator()->validate($document, self::OAS_SCHEMA_ID)->isValid(),
            'The served OpenAPI document is not a valid OpenAPI 3.1 document.',
        );

        // The meta-schema does not resolve references, so check every one lands.
        $decoded = $this->document($response);
        $components = $this->arr($decoded['components'] ?? []);
        foreach ($this->refs($decoded) as $ref) {
            $this->assertStringStartsWith('#/components/', $ref);
            [$section, $name] = \explode('/', \substr($ref, \strlen('#/components/')), 2);
            $this->assertArrayHasKey($name, $this->arr($components[$section] ?? null), \sprintf('Dangling reference %s.', $ref));
        }
    }

    /**
     * @return list<string>
     */
    protected function refs(mixed $node): array
    {
        if (!\is_array($node)) {
            return [];
        }

        $refs = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && \is_string($value)) {
                $refs[] = $value;
            } else {
                $refs = [...$refs, ...$this->refs($value)];
            }
        }

        return $refs;
    }

    private function metaValidator(): Validator
    {
        $validator = new Validator();
        $resolver = $validator->resolver();
        $this->assertNotNull($resolver);

        $register = function (string $base, array $documents) use ($resolver): void {
            foreach ($documents as $document) {
                $raw = \file_get_contents($base . $document);
                $this->assertIsString($raw);
                $decoded = \json_decode($raw);
                $this->assertInstanceOf(\stdClass::class, $decoded);
                $id = $decoded->{'$id'} ?? null;
                $this->assertIsString($id);
                $resolver->registerRaw($decoded, $id);
            }
        };

        $base = \dirname(__DIR__, 2) . '/Fixtures/OpenApiMetaSchema/';
        $register($base . 'meta-schema/', [
            'schema.json',
            'meta/core.json',
            'meta/applicator.json',
            'meta/unevaluated.json',
            'meta/validation.json',
            'meta/meta-data.json',
            'meta/format-annotation.json',
            'meta/content.json',
        ]);
        $register($base . 'oas-3.1/', [
            'schema.json',
            'dialect.json',
            'meta/base.json',
        ]);

        return $validator;
    }
}
