<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PrefixedLinks;

use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Link\ErrorLinks;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApiLaravel\Exception\ExceptionMapperInterface;

/**
 * Maps a {@see TeapotException} to a `418` whose `about` link is server-relative, so the
 * rendered error shows which base the exception renderer used.
 */
final class TeapotExceptionMapper implements ExceptionMapperInterface
{
    public function map(\Throwable $throwable): ?ErrorResponse
    {
        if (!$throwable instanceof TeapotException) {
            return null;
        }

        return ErrorResponse::fromErrors(new Error(
            status: '418',
            title: "I'm a teapot",
            links: ErrorLinks::withoutBaseUri(new Link('/errors/teapot')),
        ));
    }
}
