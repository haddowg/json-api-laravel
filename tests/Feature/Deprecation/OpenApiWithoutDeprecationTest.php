<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Deprecation;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\Deprecation\WriterResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\DeprecationErrors\OneResourceServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A document with no deprecation markers keeps the baseline contract stamp.
 *
 * @internal
 */
final class OpenApiWithoutDeprecationTest extends Orchestra
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
        $config->set('one-resource.class', WriterResource::class);

        return [JsonApiServiceProvider::class, OneResourceServiceProvider::class];
    }

    #[Test]
    public function theContractStampIsUnchanged(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();

        self::assertArrayHasKey('/writers', $this->arrayAt($doc, 'paths'));
        self::assertSame(GeneratorContract::BASELINE, $this->at($doc, 'info', 'x-generator', 'contract'));
        self::assertStringNotContainsString('"deprecated"', \json_encode($doc, \JSON_THROW_ON_ERROR));
    }
}
