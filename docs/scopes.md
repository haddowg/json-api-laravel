# OAuth scopes

A request can be refused when its access token lacks an OAuth scope the operation needs.
Scopes are opaque strings: the package compares them exactly unless you plug in a matcher
that adds wildcards or a hierarchy. They only restrict. Your policies still run after the
scope check, so a request with every scope can still be denied by a policy.

The check and the OpenAPI projection come from core, which documents the rules in full in
[OAuth scopes](https://github.com/haddowg/json-api/blob/main/docs/scopes.md). This page covers
how to turn them on in Laravel.

## Turning it on

Bind the services you need in your own service provider. None of them are named in config,
so `config:cache` keeps working.

| Contract (`haddowg\JsonApi\Scope\`) | What it does |
| --- | --- |
| `GrantedScopesProviderInterface` | Reads the scopes the request's token grants. Enforcement runs only when this is bound. Return `null` for a request with no scoped token, such as a session login, and it is not checked. |
| `OperationScopeResolverInterface` | Says which scopes an operation needs. Without it, an operation needs none unless an attribute declares them. |
| `ScopeMatcherInterface` | Decides whether the granted scopes cover a required one. The default is an exact match. |
| `ScopeCatalogInterface` | Describes each scope, for the OpenAPI document. |

```php
use haddowg\JsonApi\Scope\GrantedScopesProviderInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;
use haddowg\JsonApi\Scope\ScopeCatalogInterface;

public function register(): void
{
    $this->app->singleton(GrantedScopesProviderInterface::class, PassportScopes::class);
    $this->app->singleton(OperationScopeResolverInterface::class, ApiScopes::class);
    $this->app->singleton(ScopeCatalogInterface::class, ApiScopes::class);
}
```

The grant provider is handed the PSR-7 request the package built from Laravel's, carrying the
same headers. A Passport app might read the token off the authenticated user:

```php
final class PassportScopes implements GrantedScopesProviderInterface
{
    public function granted(ServerRequestInterface $request): ?array
    {
        $token = request()->user()?->token();

        return $token === null ? null : $token->scopes;
    }
}
```

The resolver is asked once per operation, with the type's metadata and an `OperationKey`
naming the operation's kind, type, relation or action, method and an action's declared
safety. The type metadata's `resourceClass()` names your resource, so a resolver can read
your own attributes off it.

```php
final class ApiScopes implements OperationScopeResolverInterface, ScopeCatalogInterface
{
    public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
    {
        if ($operation->kind === OperationKind::Atomic) {
            return [];
        }

        $reads = $operation->method === 'GET' || $operation->safety?->readOnly === true;

        return [$operation->type . ($reads ? ':read' : ':write')];
    }

    public function all(): array
    {
        return ['albums:read' => 'Read albums.', 'albums:write' => 'Change albums.'];
    }
}
```

## Declaring scopes on a resource

An attribute overrides the resolver for the operations it names.

```php
#[AsJsonApiResource(operationScopes: [
    'delete' => ['library:write', 'library:admin'],
    'updateRelationship.tracks' => ['library:write'],
])]
final class PlaylistResource extends AbstractResource {}
```

`operationScopes` is keyed exactly like `operationExamples`: `fetchCollection`, `fetchOne`,
`create`, `update`, `delete`, or `fetchRelated`, `fetchRelationship`, `updateRelationship`,
`addRelationship` or `removeRelationship` followed by a dot and the relation name. `[]` means
the operation needs no scope. A write the type does not expose is refused when the attribute
is built. `fetchOne` and `fetchCollection` may always be named, because an `?include`
reaching the type is checked against them. `#[AsJsonApiSerializer]` takes the same option.

A custom action declares its own:

```php
#[AsJsonApiAction(type: 'albums', path: 'artwork', scopes: ['catalog:write', 'catalog:media'])]
```

`scopes: null`, the default, asks the resolver. The Atomic Operations endpoint reads
`jsonapi.atomic_operations.scopes`, a list or `null`.

## What gets checked

- The operation's own scopes, after query validation and before your policies run. A refusal
  is a `403` with the code `INSUFFICIENT_SCOPE`, `meta.requiredScopes` and
  `meta.missingScopes`, and an RFC 6750 header:
  `WWW-Authenticate: Bearer error="insufficient_scope", scope="library:write library:admin"`.
- A related or relationship read also needs the read scopes of the type it returns.
- Each hop of an explicit `?include` needs the read scopes of the type it reaches. A refused
  hop is a `403` naming the path in `meta.include`.
- A resource included by default that the caller may not read is left out of `included`. Its
  identifier stays in the linkage and the request succeeds.
- An Atomic Operations batch needs the endpoint's scopes, and every operation in it is
  checked as the equivalent request would be, before the transaction opens. The first
  operation that fails refuses the whole batch with `source.pointer`
  `/atomic:operations/<index>`, and nothing is written.

An operation declared public (an ability of `false`, a relation's `security(read: false)`)
cannot need a scope. Giving it one fails the table build.

## Where the scopes are kept

Each server's scopes are resolved into a table once.
[`jsonapi:optimize`](optimize.md) builds every server's table first and fails when the
resolver throws, when an override names an operation the type does not have, or when a public
operation is given a scope. With `jsonapi.discovery.cache` set it writes the tables into the
discovery snapshot, and a request loads them from there.

Without a snapshot the table is built on first use and kept in the cache store named by
`jsonapi.scopes.cache_store` (null is the default store). It is keyed by a fingerprint of the
discovered classes and their files, so editing a resource builds a new one. A resolver that
reads something else, such as config, needs `php artisan jsonapi:clear` after that changes.

If a table lacks an entry, as an out-of-date snapshot can, the operation is resolved on the
spot and a warning is logged. A missing entry never lets a request through unchecked.

## The OpenAPI document

Each scoped operation's `security` names its scopes under the scheme
`jsonapi.openapi.security.scope_scheme` names, `oauth2` by default. Declare that scheme under
`jsonapi.openapi.security.schemes`, as an `oauth2` scheme so its flows list the scope
catalogue:

```php
'security' => [
    'schemes' => [
        'oauth2' => [
            'type' => 'oauth2',
            'flows' => ['clientCredentials' => ['tokenUrl' => 'https://example.com/oauth/token']],
        ],
    ],
    'scope_scheme' => 'oauth2',
],
```

A document in which no operation needs a scope is unchanged. One with scopes lists
`INSUFFICIENT_SCOPE` in its error catalogue. Scopes arrived in contract 16, but every
document now carries 18, the baseline since the links components were shared. `jsonapi:openapi:export` warns about
each required scope neither the catalogue nor the scheme's flows declare, and fails on them
with `--strict`. A scope scheme that is not `oauth2` is a warning only.

## Listing what a caller can do

Core's `OperationCatalog` lists every operation a server exposes with the ability guarding
it and the scopes it needs, and the package binds it with its two Laravel halves. The security
source reports the ability the [authorization](authorization.md) rules would check: a renamed
or convention CRUD ability, a relation's read or mutate ability, an action's ability. A type
with no policy and no defined ability reports none.

```php
use haddowg\JsonApi\Catalog\OperationCatalog;
use haddowg\JsonApi\Catalog\SecurityEvaluatorInterface;
use haddowg\JsonApi\Catalog\SecurityVerdict;
use haddowg\JsonApi\Scope\ScopeChecker;

$evaluator = app(SecurityEvaluatorInterface::class);
$checker = app(ScopeChecker::class);

$callable = [];
foreach (app(OperationCatalog::class)->forServer('default') as $operation) {
    if ($checker->satisfies($operation, $granted)
        && $evaluator->evaluate($operation, $request->user()) !== SecurityVerdict::Denied) {
        $callable[] = $operation->operationId;
    }
}
```

The evaluator inspects the ability through `Gate::forUser($user)->inspect()`. It answers
`Depends` instead when the policy method or `Gate::define()` callback takes the resource as
well as the user, or when the policy has a `before()` method, since the answer then depends on
the resource. A public or unguarded operation is `Allowed`. `ScopeChecker` uses the same
matcher as enforcement.

See [ADR 0061](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0061-scope-tables-live-in-the-snapshot-and-the-catalogue-reads-the-gate.md)
for why the tables are kept where they are and how the evaluator decides.
