<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\NamedExtensions\NamedExtensionsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * The attribute can't see a type's relations, so an `operationExtensions` name for a relation
 * the type lacks fails when core projects the document.
 *
 * @internal
 */
final class OpenApiNamedOperationExtensionsTest extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            NamedExtensionsServiceProvider::class,
        ];
    }

    #[Test]
    public function a_name_for_a_relation_the_type_lacks_fails_the_projection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"fetchRelated.owner"');

        $this->app?->make(DocumentFactory::class)->forServer('default');
    }
}
