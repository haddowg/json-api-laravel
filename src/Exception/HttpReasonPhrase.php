<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Exception;

/**
 * The status → reason-phrase table used by the {@see JsonApiExceptionRenderer} for a
 * Laravel/Symfony `HttpException` or an authorization/authentication status error, so
 * every status maps to a consistent error-object `title`. Ported verbatim from the
 * Symfony bundle.
 */
final class HttpReasonPhrase
{
    public static function of(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            409 => 'Conflict',
            415 => 'Unsupported Media Type',
            422 => 'Unprocessable Entity',
            default => $status >= 500 ? 'Server Error' : 'Error',
        };
    }
}
