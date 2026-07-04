<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Exception;

use haddowg\JsonApi\Response\ErrorResponse;

/**
 * The application-extensible seam for mapping a non-JSON:API throwable to a JSON:API
 * {@see ErrorResponse} (the Laravel translation of the Symfony bundle's
 * `ExceptionMapperInterface`). A mapper is consulted only for throwables that are NOT
 * a core {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} — that arm is
 * always handled first and never overridden. Returning `null` declines the throwable,
 * so the next mapper (or the built-in fallback) handles it.
 *
 * Bind an implementation and tag it with `jsonapi.exception_mapper` to register it.
 */
interface ExceptionMapperInterface
{
    /**
     * The error response for `$throwable`, or `null` to decline it.
     */
    public function map(\Throwable $throwable): ?ErrorResponse;
}
