<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Authorization;

use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionFilters;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataPersister\ReportsCurrentOwners;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Http\JsonApiController;
use haddowg\JsonApiLaravel\OpenApi\Metadata\RelationMetadata;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApiLaravel\Operation\TargetResolver;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Which operations the package's authorization and each route's middleware can refuse,
 * read from what {@see Authorizer} enforces and the middleware Laravel runs in front of
 * the route.
 *
 * The Gate refuses with `403` whether or not the caller is signed in, so only middleware
 * answers `401`. A gate counts when {@see Authorizer::authorize()} would enforce it: a
 * declared policy or ability, or a model policy or Gate ability the convention name finds.
 * An ability declared `true` counts too, since it says something outside the package
 * enforces it.
 *
 * A middleware this class doesn't know may refuse either way, so it counts for both, as
 * does an operation whose route it can't find.
 *
 * @internal
 */
final class OperationSecurityFacts
{
    /**
     * @var array<string, list<Route>>|null routes by server
     */
    private ?array $routes = null;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly Gate $gate,
        private readonly DataProviderRegistry $providers,
        private readonly DataPersisterRegistry $persisters,
        private readonly ?Router $router = null,
    ) {}

    /**
     * @param list<TypeMetadataInterface> $types the server's types, to follow a flattened attribute's relation chain
     */
    public function canDenyAccess(string $server, array $types, ?TypeMetadataInterface $type, OperationKey $operation): bool
    {
        return $this->gated($server, $types, $type, $operation) || $this->middleware($server, $type, $operation)[1];
    }

    /**
     * @param list<TypeMetadataInterface> $types the server's types
     */
    public function canRequireAuthentication(string $server, array $types, ?TypeMetadataInterface $type, OperationKey $operation): bool
    {
        return $this->middleware($server, $type, $operation)[0];
    }

    /**
     * @param list<TypeMetadataInterface> $types
     */
    private function gated(string $server, array $types, ?TypeMetadataInterface $type, OperationKey $operation): bool
    {
        if ($type === null) {
            return false;
        }

        $name = $type->type();
        $relation = self::relation($type, $operation->relation);

        return match ($operation->kind) {
            OperationKind::FetchCollection => $this->enforces($server, $name, Operation::FetchCollection),
            OperationKind::FetchOne => $this->enforces($server, $name, Operation::FetchOne),
            OperationKind::Create => $this->enforces($server, $name, Operation::Create) || $this->writeGated($server, $types, $type, true),
            OperationKind::Update => $this->enforces($server, $name, Operation::Update) || $this->writeGated($server, $types, $type, false),
            OperationKind::Delete => $this->enforces($server, $name, Operation::Delete),
            OperationKind::FetchRelated, OperationKind::FetchRelationship => $relation !== null && $this->readGated($server, $name, $relation),
            OperationKind::UpdateRelationship, OperationKind::AddRelationship => $relation !== null && (
                $this->mutateGated($server, $name, $relation) || $this->reparents($server, $name, $relation)
            ),
            OperationKind::RemoveRelationship => $relation !== null && $this->mutateGated($server, $name, $relation),
            OperationKind::Action => $this->actionGated($server, $type, $operation),
            OperationKind::Atomic => false,
        };
    }

    /**
     * A relation's read permission, else its `view{Relation}` ability, else the parent's
     * `view`; and for a to-many with neither, each related type's `viewAny`.
     */
    private function readGated(string $server, string $type, RelationMetadataInterface $relation): bool
    {
        $read = $relation->securityRead();
        if ($read === false) {
            return false;
        }
        if (\is_string($read) || $read === true || $this->definesReadAbility($server, $type, $relation)) {
            return true;
        }
        if ($this->enforces($server, $type, Operation::FetchOne)) {
            return true;
        }
        if (!$relation->isToMany()) {
            return false;
        }

        foreach ($relation->relatedTypes() as $related) {
            if ($this->enforces($server, $related, Operation::FetchCollection)) {
                return true;
            }
        }

        return false;
    }

    private function mutateGated(string $server, string $type, RelationMetadataInterface $relation): bool
    {
        $mutate = $relation->securityMutate();

        return match (true) {
            $mutate === false => false,
            \is_string($mutate), $mutate === true => true,
            default => $this->enforces($server, $type, Operation::Update),
        };
    }

    /**
     * A create or update is also refused by a relation's own mutate ability on linkage the
     * body sets, a member it takes from another parent, and the related type's update
     * check on an attribute flattened onto a related row.
     *
     * @param list<TypeMetadataInterface> $types
     */
    private function writeGated(string $server, array $types, TypeMetadataInterface $type, bool $creating): bool
    {
        foreach ($type->relations() as $relation) {
            if (\is_string($relation->securityMutate()) || $this->reparents($server, $type->type(), $relation)) {
                return true;
            }
        }

        foreach ($type->fields() as $field) {
            $via = $field->relatedVia();
            if ($via === null || $field->isReadOnly($creating)) {
                continue;
            }
            foreach (self::ownerTypes($types, $type, $via) as $owner) {
                if ($owner === null || $this->enforces($server, $owner, Operation::Update)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a write can take a member another parent holds, which then needs the
     * related type's `update` check when the other parent's own refuses.
     */
    private function reparents(string $server, string $type, RelationMetadataInterface $relation): bool
    {
        if (!$this->persisters->supportsType($type)) {
            return false;
        }

        $persister = $this->persisters->forType($type);
        $definition = $relation instanceof RelationMetadata ? $relation->relation() : null;
        $moves = match (true) {
            !$persister instanceof ReportsCurrentOwners => false,
            $persister instanceof EloquentDataPersister && $definition !== null => $persister->canMoveMembers($type, $definition),
            $persister instanceof InMemoryDataPersister && $definition !== null => $definition instanceof HasOne || ($definition instanceof HasMany && !$definition instanceof BelongsToMany),
            default => true,
        };
        if (!$moves) {
            return false;
        }

        foreach ($relation->relatedTypes() as $related) {
            if ($this->enforces($server, $related, Operation::Update)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An action's own ability, and for a collection action taking filters the type's
     * `viewAny`, which its filtered collection checks.
     */
    private function actionGated(string $server, TypeMetadataInterface $type, OperationKey $operation): bool
    {
        foreach ($type->actions() as $action) {
            if ($action->path() !== $operation->actionPath || $action->scope() !== $operation->actionScope) {
                continue;
            }

            return $action->isSecured()
                || ($operation->actionScope === ActionScope::Collection && self::takesFilters($action) && $this->enforces($server, $type->type(), Operation::FetchCollection));
        }

        return false;
    }

    private static function takesFilters(ActionMetadataInterface $action): bool
    {
        return $action instanceof DeclaresActionFilters && $action->filters() !== false && $action->filters() !== [];
    }

    /**
     * Whether {@see Authorizer::authorize()} enforces a check for `$operation` on `$type`:
     * a declared policy, a renamed ability or one declared `true`, or on the convention path
     * a policy or ability the Gate resolves for the type's model. A list or create of a type
     * with no persister has no subject to check the convention against.
     */
    private function enforces(string $server, string $type, Operation $operation): bool
    {
        $config = $this->authorizer->resourceAuthorization($server, $type);
        $declared = $config?->ability($operation);
        if ($declared === false) {
            return false;
        }
        if ($declared !== null || $config?->policy !== null) {
            return true;
        }

        if (!Authorizer::checksInstance($operation) && !$this->persisters->supportsType($type)) {
            return false;
        }

        $ability = Authorizer::conventionAbility($operation);
        if ($this->gate->has($ability)) {
            return true;
        }

        $class = $this->modelClass($type);
        if ($class === null) {
            return !$this->gate instanceof \Illuminate\Auth\Access\Gate || $this->gate->policies() !== [];
        }

        return $this->gate->getPolicyFor($class) !== null;
    }

    /**
     * Whether a `view{Relation}` ability is defined for the relation on its parent's policy
     * or the Gate, which then replaces the parent's `view` for the relation's reads.
     */
    private function definesReadAbility(string $server, string $type, RelationMetadataInterface $relation): bool
    {
        $ability = 'view' . \Illuminate\Support\Str::studly($relation->name());
        if (\in_array($ability, ['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete'], true)) {
            return false;
        }
        if ($this->gate->has($ability)) {
            return true;
        }

        $class = $this->modelClass($type);
        $policy = $this->authorizer->resourceAuthorization($server, $type)->policy ?? ($class !== null ? $this->gate->getPolicyFor($class) : null);

        return (\is_object($policy) || \is_string($policy)) && \method_exists($policy, $ability);
    }

    /**
     * The model class a type's rows are, found as {@see GuardedTypes} finds it.
     *
     * @return class-string|null
     */
    private function modelClass(string $type): ?string
    {
        if ($this->providers->supportsType($type)) {
            $provider = $this->providers->forType($type);
            if ($provider instanceof EloquentDataProvider) {
                return $provider->modelClassFor($type);
            }
            if ($provider instanceof InMemoryDataProvider) {
                $row = $provider->store()->all()[0] ?? null;
                if (\is_object($row)) {
                    return $row::class;
                }
            }
        }

        return $this->persisters->supportsType($type) ? $this->persisters->forType($type)->instantiate($type)::class : null;
    }

    /**
     * What the route's middleware can answer: `401`, and `403`.
     *
     * @return array{bool, bool}
     */
    private function middleware(string $server, ?TypeMetadataInterface $type, OperationKey $operation): array
    {
        $route = $this->routeFor($server, $type, $operation);
        if ($route === null || $this->router === null) {
            return [true, true];
        }

        $authenticates = false;
        $denies = false;
        foreach ($this->router->gatherRouteMiddleware($route) as $middleware) {
            if (!\is_string($middleware)) {
                return [true, true];
            }

            [$authenticates, $denies] = self::classify(\explode(':', $middleware, 2)[0], $authenticates, $denies);
        }

        return [$authenticates, $denies];
    }

    /**
     * Folds what one middleware can refuse into the route's answer.
     *
     * @return array{bool, bool}
     */
    private static function classify(string $middleware, bool $authenticates, bool $denies): array
    {
        return match (true) {
            \is_a($middleware, \Illuminate\Auth\Middleware\Authenticate::class, true),
            \is_a($middleware, \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class, true) => [true, $denies],
            \is_a($middleware, \Illuminate\Auth\Middleware\Authorize::class, true),
            \is_a($middleware, \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class, true),
            \is_a($middleware, \Illuminate\Routing\Middleware\ValidateSignature::class, true) => [$authenticates, true],
            // The package's own middleware and the framework's request plumbing refuse nobody.
            \str_starts_with($middleware, 'haddowg\\JsonApiLaravel\\'),
            \str_starts_with($middleware, 'Illuminate\\') && !\str_starts_with($middleware, 'Illuminate\\Auth\\') => [$authenticates, $denies],
            default => [true, true],
        };
    }

    private function routeFor(string $server, ?TypeMetadataInterface $type, OperationKey $operation): ?Route
    {
        foreach ($this->routes()[$server] ?? [] as $route) {
            if (!\in_array($operation->method, $route->methods(), true)) {
                continue;
            }
            if (self::serves($route, $type?->type(), $operation)) {
                return $route;
            }
        }

        return null;
    }

    private static function serves(Route $route, ?string $type, OperationKey $operation): bool
    {
        $defaults = $route->defaults;
        if ($operation->kind === OperationKind::Atomic) {
            return ($defaults[JsonApiController::ATOMIC_ATTRIBUTE] ?? false) === true;
        }
        if (($defaults[TargetResolver::TYPE_ATTRIBUTE] ?? null) !== $type) {
            return false;
        }

        $action = $defaults[JsonApiController::ACTION_ATTRIBUTE] ?? null;
        if ($operation->kind === OperationKind::Action) {
            return $action === $operation->actionPath
                && ($defaults[JsonApiController::ACTION_SCOPE_ATTRIBUTE] ?? null) === $operation->actionScope?->name;
        }
        if ($action !== null) {
            return false;
        }

        $uri = $route->uri();
        $relationship = \str_contains($uri, '{relationship}');
        $linkage = ($defaults[TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE] ?? false) === true;
        $hasId = \str_contains($uri, '{id}');

        return match ($operation->kind) {
            OperationKind::FetchCollection, OperationKind::Create => !$hasId,
            OperationKind::FetchOne, OperationKind::Update, OperationKind::Delete => $hasId && !$relationship,
            OperationKind::FetchRelated => $relationship && !$linkage,
            OperationKind::FetchRelationship, OperationKind::UpdateRelationship, OperationKind::AddRelationship, OperationKind::RemoveRelationship => $linkage,
        };
    }

    /**
     * @return array<string, list<Route>>
     */
    private function routes(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }

        $this->routes = [];
        foreach ($this->router?->getRoutes()->getRoutes() ?? [] as $route) {
            $server = $route->defaults[TargetResolver::SERVER_ATTRIBUTE] ?? null;
            if (\is_string($server)) {
                $this->routes[$server][] = $route;
            }
        }

        return $this->routes;
    }

    /**
     * The types a flattened attribute's relation chain can end on; null when a hop can't be
     * followed, since the write is then refused outright.
     *
     * @param list<TypeMetadataInterface> $types
     *
     * @return list<string|null>
     */
    private static function ownerTypes(array $types, TypeMetadataInterface $type, string $via): array
    {
        $current = [$type];
        foreach (\explode('.', $via) as $hop) {
            $next = [];
            foreach ($current as $owner) {
                foreach (self::relation($owner, $hop)?->relatedTypes() ?? [] as $related) {
                    foreach ($types as $candidate) {
                        if ($candidate->type() === $related) {
                            $next[] = $candidate;
                        }
                    }
                }
            }
            $current = $next;
        }

        $owners = \array_values(\array_unique(\array_map(static fn(TypeMetadataInterface $owner): string => $owner->type(), $current)));

        return $owners === [] ? [null] : $owners;
    }

    private static function relation(TypeMetadataInterface $type, ?string $name): ?RelationMetadataInterface
    {
        foreach ($name === null ? [] : $type->relations() as $relation) {
            if ($relation->name() === $name) {
                return $relation;
            }
        }

        return null;
    }
}
