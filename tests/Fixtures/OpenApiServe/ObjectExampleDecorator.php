<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe;

use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApiLaravel\OpenApi\OpenApiFactoryInterface;

/**
 * Adds a component whose example is an {@see ObjectExample}.
 */
final class ObjectExampleDecorator implements OpenApiFactoryInterface
{
    public function decorate(OpenApi $document, string $server): OpenApi
    {
        return $document->withComponents($document->components->withSchema('ObjectExample', Schema::ofType('string')->withExample(new ObjectExample())));
    }
}
