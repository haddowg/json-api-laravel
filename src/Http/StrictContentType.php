<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Request\MediaType;

/**
 * Decides which requests must declare their body's media type: every `POST`,
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

    /**
     * Whether the request has no body and declares the JSON:API media type, as a client does
     * on every `POST`. Any action takes it, whatever its `accepts` lists: a browser can't send
     * that media type cross-site without a preflight.
     */
    public static function isBodylessJsonApi(JsonApiRequestInterface $request): bool
    {
        return (string) $request->getBody() === ''
            && \in_array($request->getParsedBody(), [null, []], true)
            && MediaType::isJsonApi($request->getHeaderLine('content-type'));
    }
}
