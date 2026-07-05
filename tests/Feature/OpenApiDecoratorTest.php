<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\TitleOpenApiFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests the OpenAPI decorator seam (PLAN decision 11): a tagged
 * {@see \haddowg\JsonApiLaravel\OpenApi\OpenApiFactoryInterface} runs after the core
 * projection and gets the last word over the projected document. Because every build
 * path flows through the {@see DocumentFactory}, the decorator applies to the warmer,
 * controller and CLI uniformly.
 *
 * @internal
 */
final class OpenApiDecoratorTest extends TestCase
{
    use InteractsWithOpenApiDocument;

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Bind + tag the decorator (before the DocumentFactory singleton is resolved) so it
        // joins the decorator chain.
        $app->singleton(TitleOpenApiFactory::class);
        $app->tag([TitleOpenApiFactory::class], JsonApiServiceProvider::OPENAPI_FACTORY_TAG);
    }

    #[Test]
    #[Group('openapi')]
    public function a_tagged_decorator_mutates_the_projected_document(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer()->toArray();

        $this->assertSame(TitleOpenApiFactory::TITLE, $this->at($doc, 'info', 'title'));
    }
}
