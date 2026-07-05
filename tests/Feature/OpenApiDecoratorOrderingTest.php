<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\SecondTitleOpenApiFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\TitleOpenApiFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * When two OpenAPI decorators mutate the same member, the LATER-registered one wins — they
 * are applied in registration order (Laravel's `tagged()` carries no priority; later binding
 * wins). Guards the {@see DocumentFactory} ordering against a silent flip.
 *
 * @internal
 */
final class OpenApiDecoratorOrderingTest extends TestCase
{
    use InteractsWithOpenApiDocument;

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Registered first, then second — both rewrite info.title, so the second must win.
        $app->singleton(TitleOpenApiFactory::class);
        $app->singleton(SecondTitleOpenApiFactory::class);
        $app->tag([TitleOpenApiFactory::class, SecondTitleOpenApiFactory::class], JsonApiServiceProvider::OPENAPI_FACTORY_TAG);
    }

    #[Test]
    #[Group('openapi')]
    public function theLaterRegisteredDecoratorGetsTheFinalWord(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer()->toArray();

        $this->assertSame(SecondTitleOpenApiFactory::TITLE, $this->at($doc, 'info', 'title'));
    }
}
