<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use Illuminate\Http\Request;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Keeps Laravel's middleware-rewritten JSON input out of the PSR request handed to core.
 *
 * For a JSON body (`application/vnd.api+json`, its `ext=` variants, any `+json` type)
 * Laravel's input bag is the decoded body, and the global `TrimStrings` and
 * `ConvertEmptyStringsToNull` middleware rewrite it in place, so `"  Spaced  "` would arrive
 * trimmed and `""` as `null`. The PSR bridge copies that bag in as the parsed body; this
 * swaps in a fresh decode of the raw content. The body stream is the raw content either way,
 * which is what a Raw-input action reads.
 *
 * @internal
 */
final class WireBody
{
    public static function apply(ServerRequestInterface $psrRequest, Request $request): ServerRequestInterface
    {
        // The bridge already decodes a plain `application/json` body from the raw content.
        if (!$request->isJson() || $request->getContentTypeFormat() === 'json') {
            return $psrRequest;
        }

        $decoded = \json_decode($request->getContent(), true);

        // Malformed JSON leaves the bridge's empty bag, so core decodes the raw body and
        // raises its invalid-JSON error. A scalar keeps Laravel's array cast, which core
        // rejects as a document missing its top-level members.
        if (!\is_array($decoded)) {
            return $psrRequest;
        }

        return $psrRequest->withParsedBody($decoded);
    }
}
