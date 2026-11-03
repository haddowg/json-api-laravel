<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\CompositeInMemoryServiceProvider;

/**
 * A structured list's element reaches the OpenAPI document as its `items` schema, and the
 * document carries the generator contract that announces it.
 *
 * @internal
 */
final class StructuredListOpenApiTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, CompositeInMemoryServiceProvider::class];
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
    #[Group('openapi')]
    public function a_structured_list_projects_its_element_as_the_items_schema(): void
    {
        $doc = $this->document();

        $steps = $this->arrayAt($doc, 'components', 'schemas', 'PipelinesCreateAttributes', 'properties', 'steps');
        self::assertSame(3, $steps['maxItems'] ?? null);
        self::assertSame(['propertyName' => 'operation'], $this->arrayAt($steps, 'items', 'discriminator'));
        self::assertSame('find_replace', $this->stringAt($steps, 'items', 'oneOf', 1, 'properties', 'operation', 'const'));

        $checks = $this->arrayAt($doc, 'components', 'schemas', 'PipelinesCreateAttributes', 'properties', 'checks', 'items');
        self::assertSame('object', $checks['type'] ?? null);
        self::assertSame(['label'], $checks['required'] ?? null);
        self::assertSame(['pattern'], $this->arrayAt($checks, 'properties', 'rules', 'items', 'required'));
    }

    #[Test]
    #[Group('openapi')]
    public function an_update_requires_inside_an_element_what_a_create_does(): void
    {
        $doc = $this->document();

        $update = $this->arrayAt($doc, 'components', 'schemas', 'PipelinesUpdateAttributes', 'properties');
        self::assertSame(['label'], $this->arrayAt($update, 'checks', 'items', 'required'));
        self::assertSame(['params', 'operation'], $this->arrayAt($update, 'steps', 'items', 'oneOf', 1, 'required'));
    }

    #[Test]
    #[Group('openapi')]
    public function the_document_carries_the_structured_list_contract(): void
    {
        self::assertSame(
            ['contract' => GeneratorContract::STRUCTURED_LIST_ELEMENTS],
            $this->arrayAt($this->document(), 'info', 'x-generator'),
        );
    }
}
