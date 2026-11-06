<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Deprecation;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\DeprecationErrors\BadSunsetResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\DeprecationErrors\OneResourceServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\DeprecationErrors\UnknownRelationResource;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * What only the projection can check: a relation the type lacks fails it, and a sunset that
 * is not a date leaves the sunset sentence out.
 *
 * @internal
 */
final class OpenApiDeprecationDeclarationTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('one-resource.class', $this->name() === 'aSunsetThatIsNotADateNamesNoDate' ? BadSunsetResource::class : UnknownRelationResource::class);

        return [JsonApiServiceProvider::class, OneResourceServiceProvider::class];
    }

    #[Test]
    public function anOperationOfARelationTheTypeLacksFailsTheProjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The type "gadgets" declares the operation "fetchRelated.owner" deprecated, which it does not expose.');

        $this->resolve(DocumentFactory::class)->forServer('default');
    }

    #[Test]
    public function aSunsetThatIsNotADateNamesNoDate(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();

        self::assertTrue($this->at($doc, 'paths', '/relays/{id}', 'get', 'deprecated'));
        self::assertStringNotContainsString('sunset date', $this->stringAt($doc, 'paths', '/relays/{id}', 'get', 'description'));
    }
}
