<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Security;

use haddowg\JsonApi\Scope\GrantedScopesProviderInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads the granted scopes off the example's bearer token, `Bearer <user>#<scope> <scope>`.
 * A token with no `#`, or no token at all, carries no scopes, so the request is not checked
 * for them; `Bearer alice#` grants nothing.
 */
final class BearerTokenScopes implements GrantedScopesProviderInterface
{
    public function granted(ServerRequestInterface $request): ?array
    {
        $header = $request->getHeaderLine('Authorization');
        if (\preg_match('/^Bearer\s+(.+)$/i', $header, $match) !== 1) {
            return null;
        }

        $hash = \strpos($match[1], '#');
        if ($hash === false) {
            return null;
        }

        $scopes = \preg_split('/\s+/', \trim(\substr($match[1], $hash + 1)), -1, \PREG_SPLIT_NO_EMPTY);

        return $scopes === false ? [] : $scopes;
    }
}
