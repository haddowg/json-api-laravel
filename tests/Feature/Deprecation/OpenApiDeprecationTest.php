<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Deprecation;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\Deprecation\DeprecationServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * The deprecation a type, an operation, an action or a relation declares reaches the
 * OpenAPI document.
 *
 * @internal
 */
final class OpenApiDeprecationTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    private const string SUNSET_SENTENCE = 'This operation may stop responding on or after 2028-01-01, its sunset date.';

    /**
     * @var array<array-key, mixed>
     */
    private array $doc;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, DeprecationServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->doc = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();
    }

    #[Test]
    public function aDeprecatedTypeMarksEveryOperation(): void
    {
        foreach (['/archives', '/archives/{id}'] as $path) {
            self::assertTrue($this->at($this->doc, 'paths', $path, 'get', 'deprecated'), $path);
            self::assertStringNotContainsString('sunset date', $this->stringAt($this->doc, 'paths', $path, 'get', 'description'));
        }
    }

    #[Test]
    public function aSunsetIsNamedOnEveryOperationOfTheType(): void
    {
        foreach (['/vaults', '/vaults/{id}'] as $path) {
            self::assertTrue($this->at($this->doc, 'paths', $path, 'get', 'deprecated'), $path);
            self::assertStringEndsWith(self::SUNSET_SENTENCE, $this->stringAt($this->doc, 'paths', $path, 'get', 'description'));
        }
    }

    #[Test]
    public function aNamedOperationIsMarkedAndItsSiblingsAreNot(): void
    {
        self::assertTrue($this->at($this->doc, 'paths', '/articles/{id}', 'delete', 'deprecated'));
        self::assertTrue($this->at($this->doc, 'paths', '/articles/{id}/writer', 'get', 'deprecated'));

        foreach ([
            ['/articles', 'get'],
            ['/articles', 'post'],
            ['/articles/{id}', 'get'],
            ['/articles/{id}', 'patch'],
            ['/articles/{id}/relationships/writer', 'get'],
        ] as [$path, $method]) {
            self::assertArrayNotHasKey('deprecated', $this->arrayAt($this->doc, 'paths', $path, $method), $method . ' ' . $path);
        }
    }

    #[Test]
    public function aDeprecatedActionIsMarkedAndItsSiblingIsNot(): void
    {
        self::assertTrue($this->at($this->doc, 'paths', '/articles/{id}/-actions/publish', 'post', 'deprecated'));
        self::assertArrayNotHasKey('deprecated', $this->arrayAt($this->doc, 'paths', '/articles/{id}/-actions/share', 'post'));
    }

    #[Test]
    public function aDeprecatedRelationAndAttributeAreMarkedOnTheirProperties(): void
    {
        self::assertTrue($this->at($this->doc, 'components', 'schemas', 'ArticlesResource', 'properties', 'relationships', 'properties', 'writer', 'deprecated'));
        self::assertTrue($this->at($this->doc, 'components', 'schemas', 'ArticlesAttributes', 'properties', 'subtitle', 'deprecated'));
        self::assertArrayNotHasKey('deprecated', $this->arrayAt($this->doc, 'components', 'schemas', 'ArticlesAttributes', 'properties', 'title'));
    }

    #[Test]
    public function aCurrentTypeIsUntouched(): void
    {
        foreach (['/writers', '/writers/{id}'] as $path) {
            $operation = $this->arrayAt($this->doc, 'paths', $path, 'get');
            self::assertArrayNotHasKey('deprecated', $operation, $path);
            self::assertStringNotContainsString('sunset date', $this->stringAt($operation, 'description'));
        }
    }

    #[Test]
    public function theDocumentDeclaresTheDeprecationContract(): void
    {
        self::assertSame(GeneratorContract::DEPRECATION, $this->at($this->doc, 'info', 'x-generator', 'contract'));
    }
}
