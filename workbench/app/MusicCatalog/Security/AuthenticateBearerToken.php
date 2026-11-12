<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Security;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

/**
 * The catalogue's twin of the bundle example's stateless `access_token` firewall: a
 * request with no `Authorization` header goes through anonymously, and one whose header
 * isn't a `Bearer` token is refused with `401`. The example has no user store, so any
 * well-formed token passes.
 */
final class AuthenticateBearerToken extends Authenticate
{
    /**
     * @param string ...$guards
     */
    public function handle($request, \Closure $next, ...$guards): mixed
    {
        \assert($request instanceof Request);

        $header = $request->headers->get('Authorization');
        if ($header !== null && \preg_match('/^Bearer\s+\S/i', $header) !== 1) {
            throw new AuthenticationException('The bearer token is malformed.');
        }

        return $next($request);
    }
}
