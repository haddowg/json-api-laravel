<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Operation;

use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Resource\DeclaresSingletonTypesInterface;
use haddowg\JsonApi\Resource\SingletonContext;
use Illuminate\Http\Request;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the caller's id for a singleton target, once per request, so the operation that
 * follows is an ordinary fetch, update, relationship or action on that id.
 *
 * The type's `resolveSingletonId()` receives a {@see SingletonContext} carrying the PSR-7
 * request and two attributes: {@see USER_ATTRIBUTE}, the authenticated user or `null`, and
 * {@see REQUEST_ATTRIBUTE}, the Illuminate request. The id is remembered on the request,
 * so every later read of its target, such as `$request->jsonApi()->target()`, carries it.
 * A type that names no resource for the caller answers `404`.
 */
final readonly class SingletonIdResolver
{
    /**
     * The attribute carrying the authenticated user, the request's
     * {@see \Illuminate\Contracts\Auth\Authenticatable}, or `null` for a guest.
     */
    public const string USER_ATTRIBUTE = 'user';

    /**
     * The attribute carrying the Illuminate {@see Request}.
     */
    public const string REQUEST_ATTRIBUTE = 'request';

    /**
     * The request attribute holding the ids resolved for the request, by type.
     */
    private const string RESOLVED_IDS = '_jsonapi_singleton_ids';

    /**
     * `$target` with the caller's id. A target that isn't a singleton, or already carries
     * the id, is returned as it is.
     *
     * @throws ResourceNotFound when the type names no resource for the caller
     */
    public function resolve(DeclaresSingletonTypesInterface $server, Target $target, ?ServerRequestInterface $psrRequest, ?Request $request = null): Target
    {
        if (!$target->singleton || $target->id !== null) {
            return $target;
        }

        $resolved = $request !== null ? self::resolvedIds($request) : [];
        if (!\array_key_exists($target->type, $resolved)) {
            $context = new SingletonContext($target->type, $psrRequest, [
                self::USER_ATTRIBUTE => $request?->user(),
                self::REQUEST_ATTRIBUTE => $request,
            ]);
            $resolved[$target->type] = $server->resolveSingletonId($target->type, $context);
            $request?->attributes->set(self::RESOLVED_IDS, $resolved);
        }

        $id = $resolved[$target->type];
        if ($id === null || $id === '') {
            throw new ResourceNotFound();
        }

        return $target->withId($id);
    }

    /**
     * The id already resolved on `$request` for the singleton type `$type`, or `null` when
     * none has been.
     */
    public static function resolvedId(Request $request, string $type): ?string
    {
        $id = self::resolvedIds($request)[$type] ?? null;

        return $id === '' ? null : $id;
    }

    /**
     * @return array<string, ?string>
     */
    private static function resolvedIds(Request $request): array
    {
        $resolved = $request->attributes->get(self::RESOLVED_IDS);

        /** @var array<string, ?string> */
        return \is_array($resolved) ? $resolved : [];
    }
}
