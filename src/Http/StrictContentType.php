<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Decides which requests must declare their body's media type (core ADR 0172): every `POST`,
 * even one with no body, and any other unsafe method only when it carries a body.
 *
 * @internal
 */
final class StrictContentType
{
    public static function appliesTo(JsonApiRequestInterface $request): bool
    {
        $method = \strtoupper($request->getMethod());
        if (\in_array($method, ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
            return false;
        }

        if ($method === 'POST') {
            return true;
        }

        return (string) $request->getBody() !== '' || !\in_array($request->getParsedBody(), [null, []], true);
    }
}
