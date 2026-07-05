<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures;

use haddowg\JsonApi\OpenApi\Info;
use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApiLaravel\OpenApi\OpenApiFactoryInterface;

/**
 * A second OpenAPI decorator that also rewrites the document title — paired with
 * {@see TitleOpenApiFactory} to prove decorator ordering: the later-registered decorator is
 * applied last and gets the final word.
 *
 * @internal
 */
final class SecondTitleOpenApiFactory implements OpenApiFactoryInterface
{
    public const string TITLE = 'Second Decorated Title';

    public function decorate(OpenApi $document, string $server): OpenApi
    {
        return $document->withInfo(new Info(self::TITLE, '9.9.9'));
    }
}
