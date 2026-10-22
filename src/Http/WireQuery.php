<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use Illuminate\Http\Request;

/**
 * The query parameters exactly as the client sent them, parsed from the raw query string.
 *
 * Laravel's global input middleware (`TrimStrings`, `ConvertEmptyStringsToNull`) rewrite
 * the request's query bag in place. That suits form input, but the JSON:API query family is
 * a wire format core parses itself: `fields[t]=` is a valid empty fieldset that the
 * rewrite turns into a `null` core rejects, and a trimmed `filter` value would match
 * differently from the same request against the Symfony bundle. Re-parsing `QUERY_STRING`
 * with `parse_str()` gives the same array PHP built for `$_GET`, so a value the middleware
 * left alone is unchanged.
 *
 * @internal
 */
final class WireQuery
{
    /**
     * @return array<array-key, mixed>
     */
    public static function params(Request $request): array
    {
        $queryString = $request->server->get('QUERY_STRING');

        // A request built without a server query string (hand-constructed, or a runtime
        // that never sets it) has nothing to re-parse, so its query bag is all there is.
        if (!\is_string($queryString)) {
            return $request->query->all();
        }

        \parse_str($queryString, $params);

        return $params;
    }
}
