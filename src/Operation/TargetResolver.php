<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Operation;

use haddowg\JsonApi\Operation\Target;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Builds a core {@see Target} from the matched Laravel route's defaults and path
 * parameters: the `_jsonapi_type` route default plus the optional `{id}` and
 * `{relationship}` path parameters. When a `{relationship}` segment is present the
 * target names a relationship, and the `_jsonapi_relationship_endpoint` route default
 * distinguishes the linkage endpoint (`/relationships/{relationship}`, `true`) from
 * the related-resource endpoint (`/{relationship}`, `false`).
 *
 * It is a pure mapper with no container or I/O — the public seam an explicit-route
 * user (the `Route::jsonApi()` macro) relies on, and trivially unit-testable.
 */
final class TargetResolver
{
    public const string TYPE_ATTRIBUTE = '_jsonapi_type';

    public const string SERVER_ATTRIBUTE = '_jsonapi_server';

    public const string ID_ATTRIBUTE = 'id';

    public const string RELATIONSHIP_ATTRIBUTE = 'relationship';

    public const string RELATIONSHIP_ENDPOINT_ATTRIBUTE = '_jsonapi_relationship_endpoint';

    /**
     * Resolves the target from the request's matched route, or `null` when there is
     * no matched route or it carries no `_jsonapi_type` (not a JSON:API route).
     */
    public function resolveFromRequest(Request $request): ?Target
    {
        $route = $request->route();
        if (!$route instanceof Route) {
            return null;
        }

        $type = $route->defaults[self::TYPE_ATTRIBUTE] ?? null;
        if (!\is_string($type) || $type === '') {
            return null;
        }

        $id = $route->parameter(self::ID_ATTRIBUTE);

        $relationship = $route->parameter(self::RELATIONSHIP_ATTRIBUTE);
        $relationship = \is_string($relationship) && $relationship !== '' ? $relationship : null;

        return new Target(
            $type,
            \is_string($id) ? $id : null,
            $relationship,
            $relationship !== null && ($route->defaults[self::RELATIONSHIP_ENDPOINT_ATTRIBUTE] ?? false) === true,
        );
    }
}
