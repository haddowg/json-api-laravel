<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\ResourceDescriptor;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\ExtensionsInMemoryServiceProvider;

/**
 * `#[AsJsonApiResource(extensions:, operationExtensions:)]` reaches the served OpenAPI
 * document: the type-wide set on every operation of the type, and a per-operation set merged
 * over it on its CRUD operation.
 *
 * @internal
 */
final class OpenApiVendorExtensionsTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            ExtensionsInMemoryServiceProvider::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();
        \assert(\array_is_list($doc) === false);

        return $doc;
    }

    #[Test]
    public function the_type_wide_extensions_land_on_every_operation_of_the_type(): void
    {
        $doc = $this->document();

        foreach ([
            ['/gadgets', 'get'],
            ['/gadgets/{id}', 'get'],
            ['/gadgets/{id}', 'patch'],
            ['/gadgets/{id}/maker', 'get'],
            ['/gadgets/{id}/relationships/maker', 'get'],
            ['/gadgets/{id}/relationships/maker', 'patch'],
            ['/gadgets/{id}/-actions/inspect', 'post'],
        ] as [$path, $method]) {
            $operation = $this->arrayAt($doc, 'paths', $path, $method);
            self::assertSame('gadgets', $operation['x-feature'] ?? null, "{$method} {$path}");
            self::assertSame('beta', $operation['x-stability'] ?? null, "{$method} {$path}");
        }
    }

    #[Test]
    public function a_per_operation_set_merges_over_the_type_wide_set(): void
    {
        $doc = $this->document();

        $create = $this->arrayAt($doc, 'paths', '/gadgets', 'post');
        self::assertSame('gadgets', $create['x-feature'] ?? null);
        self::assertSame('stable', $create['x-stability'] ?? null);
        self::assertSame(['per' => 'minute', 'limit' => 10], $create['x-rate-limit'] ?? null);

        $delete = $this->arrayAt($doc, 'paths', '/gadgets/{id}', 'delete');
        self::assertTrue($delete['x-danger'] ?? null);
        self::assertSame('beta', $delete['x-stability'] ?? null);

        // A per-operation set stays on its own operation.
        self::assertArrayNotHasKey('x-danger', $this->arrayAt($doc, 'paths', '/gadgets/{id}', 'patch'));
        self::assertArrayNotHasKey('x-rate-limit', $this->arrayAt($doc, 'paths', '/gadgets', 'get'));
    }

    #[Test]
    public function a_type_without_extensions_projects_none(): void
    {
        $doc = $this->document();

        foreach ([['/makers', 'get'], ['/makers/{id}', 'get']] as [$path, $method]) {
            $keys = \array_keys($this->arrayAt($doc, 'paths', $path, $method));
            self::assertSame([], \array_values(\array_filter($keys, static fn(string|int $key): bool => \str_starts_with((string) $key, 'x-'))), "{$method} {$path}");
        }
    }

    #[Test]
    public function the_extensions_survive_the_discovery_snapshot(): void
    {
        $descriptor = null;
        foreach ($this->resolve(Discovery::class)->resources() as $candidate) {
            if ($candidate->type === 'gadgets') {
                $descriptor = $candidate;
            }
        }
        self::assertInstanceOf(ResourceDescriptor::class, $descriptor);

        $file = \tempnam(\sys_get_temp_dir(), 'jsonapi-ext');
        \assert(\is_string($file));
        \file_put_contents($file, '<?php return ' . \var_export($descriptor->toArray(), true) . ';');
        /** @var array{class: class-string<\haddowg\JsonApi\Resource\AbstractResource>, type: string, uriType: string, servers: list<string>, operations: list<string>, extensions?: array<string, mixed>, operationExtensions?: array<string, array<string, mixed>>} $data */
        $data = require $file;
        \unlink($file);
        $restored = ResourceDescriptor::fromArray($data);

        self::assertSame($descriptor->extensions, $restored->extensions);
        self::assertSame($descriptor->operationExtensions, $restored->operationExtensions);
        self::assertSame(['x-feature' => 'gadgets', 'x-stability' => 'beta'], $restored->extensions);
    }
}
