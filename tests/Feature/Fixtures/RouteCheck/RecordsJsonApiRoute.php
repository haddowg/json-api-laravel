<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Fixtures\RouteCheck;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records what `$request->isJsonApi()` and `$request->jsonApi()` answer for {@see \haddowg\JsonApiLaravel\Tests\Feature\JsonApiRouteMacroTest}.
 * Pushed as global middleware it runs with the default `global` label, before routing;
 * attached to routes it runs as `RecordsJsonApiRoute:route`, after.
 */
final class RecordsJsonApiRoute
{
    /** @var list<array{label: string, path: string, isJsonApi: bool, type: ?string}> */
    public static array $seen = [];

    public function handle(Request $request, \Closure $next, string $label = 'global'): Response
    {
        self::$seen[] = ['label' => $label, 'path' => $request->path(), 'isJsonApi' => $request->isJsonApi(), 'type' => $request->jsonApi()?->type];

        /** @var Response */
        return $next($request);
    }
}
