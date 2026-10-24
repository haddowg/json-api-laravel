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
use Workbench\App\Providers\SingularNamesInMemoryServiceProvider;

/**
 * The generated prose names one resource with the declared singular: the resource's
 * `getSingularName()` hook first, then `#[AsJsonApiResource(singular:)]`, then core's
 * inflection of the type name.
 *
 * @internal
 */
final class OpenApiSingularNameTest extends Orchestra
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
            SingularNamesInMemoryServiceProvider::class,
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
    public function the_attribute_names_the_singular(): void
    {
        $doc = $this->document();

        self::assertSame('Fetch a catalog item', $this->arrayAt($doc, 'paths', '/products/{id}', 'get')['summary'] ?? null);
        self::assertSame(
            'Fetch the related tags of a catalog item',
            $this->arrayAt($doc, 'paths', '/products/{id}/tags', 'get')['summary'] ?? null,
        );
        self::assertSame(
            'Creates a new catalog item from the supplied attributes and relationships.',
            $this->arrayAt($doc, 'paths', '/products', 'post')['description'] ?? null,
        );
    }

    #[Test]
    public function the_resource_hook_wins_over_the_attribute(): void
    {
        $doc = $this->document();

        self::assertSame('Create a product category', $this->arrayAt($doc, 'paths', '/categories', 'post')['summary'] ?? null);
        self::assertSame(
            'Returns a single product category by its `id`.',
            $this->arrayAt($doc, 'paths', '/categories/{id}', 'get')['description'] ?? null,
        );
        // A collection keeps the plural type name.
        self::assertSame('List categories', $this->arrayAt($doc, 'paths', '/categories', 'get')['summary'] ?? null);
    }

    #[Test]
    public function a_type_naming_no_singular_is_inflected(): void
    {
        self::assertSame('Fetch a shelf', $this->arrayAt($this->document(), 'paths', '/shelves/{id}', 'get')['summary'] ?? null);
    }

    #[Test]
    public function the_singular_survives_the_discovery_snapshot(): void
    {
        $descriptor = null;
        foreach ($this->resolve(Discovery::class)->resources() as $candidate) {
            if ($candidate->type === 'products') {
                $descriptor = $candidate;
            }
        }
        self::assertInstanceOf(ResourceDescriptor::class, $descriptor);

        $file = \tempnam(\sys_get_temp_dir(), 'jsonapi-singular');
        \assert(\is_string($file));
        \file_put_contents($file, '<?php return ' . \var_export($descriptor->toArray(), true) . ';');
        /** @var array{class: class-string<\haddowg\JsonApi\Resource\AbstractResource>, type: string, uriType: string, servers: list<string>, operations: list<string>, singular?: string|null} $data */
        $data = require $file;
        \unlink($file);

        // The fixture pads the noun with spaces; discovery stores it trimmed.
        self::assertSame('catalog item', ResourceDescriptor::fromArray($data)->singular);
    }
}
