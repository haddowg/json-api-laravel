<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures;

use haddowg\JsonApi\OpenApi\Info;
use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApiLaravel\OpenApi\OpenApiFactoryInterface;

/**
 * A minimal OpenAPI decorator that rewrites the document title — the fixture proving the
 * {@see OpenApiFactoryInterface} seam runs after the core projection and gets the last
 * word over the projected document.
 *
 * @internal
 */
final class TitleOpenApiFactory implements OpenApiFactoryInterface
{
    public const string TITLE = 'Decorated Title';

    public function decorate(OpenApi $document, string $server): OpenApi
    {
        return $document->withInfo(new Info(self::TITLE, '9.9.9'));
    }
}
