<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Authorization;

use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * The policy-first authorization gate (PLAN decision 7), invoked by the
 * {@see \haddowg\JsonApiLaravel\Operation\CrudOperationHandler} at each write/read
 * lifecycle point with the loaded model in hand, before persistence:
 * list→`viewAny`, read→`view`, create→`create`, update→`update`, delete→`delete`.
 *
 * Resolution order per type on the server the check runs on (from the
 * {@see \haddowg\JsonApiLaravel\Attribute\AsJsonApiResource} attribute of the resource
 * that server exposes the type through; each check takes the server name, null meaning
 * `default`):
 *  1. the per-operation ability override — a renamed ability `string` (Gate-resolved,
 *     so `Gate::define()` and a policy method of that name both work), or `false` to
 *     disable the check for that operation entirely;
 *  2. a declared `policy:` class — invoked directly, container-resolved, honouring its
 *     `before()`, while leaving the application's own `Gate::policy()` mapping untouched;
 *  3. otherwise the model's Gate-registered policy (or a defined ability) via the Gate;
 *  4. no policy and no defined ability → inert (no check), exactly like the Symfony
 *     bundle without security-core.
 *
 * A denial throws {@see \Illuminate\Auth\Access\AuthorizationException} (rendered as a
 * JSON:API `403` by the exception renderer); an authentication guard on the server's
 * route middleware produces the `401` (this gate never does — a policy denial is a
 * `403` regardless of auth state, the deliberate Laravel divergence from the bundle's
 * firewall-`401`).
 */
final class Authorizer
{
    /**
     * @var array<string, array<string, ResourceAuthorization>>
     */
    private readonly array $servers;

    /**
     * Two servers may expose the same type through different resource classes, so each
     * server keeps its own overrides and a check only ever reads the entry of the server it
     * runs on. A type a server declares nothing for falls through to the Gate.
     *
     * @param array<string, ResourceAuthorization>                $config  the `default` server's per-type overrides, keyed by JSON:API type
     * @param array<string, array<string, ResourceAuthorization>> $servers the per-type overrides keyed by server name, then type; an entry for `default` here replaces `$config`
     */
    public function __construct(
        private readonly Gate $gate,
        array $config,
        array $servers = [],
    ) {
        $this->servers = $servers + [ServerRegistry::DEFAULT_SERVER => $config];
    }

    /**
     * Authorizes `$operation` on `$type` for the current user, throwing an
     * {@see \Illuminate\Auth\Access\AuthorizationException} on denial.
     *
     * `$subject` is the loaded model (read/update/delete), the blank instance
     * (create), a blank instance minted for its class (list), or `null` when none is
     * available (a read-only collection with no persister). A null subject leaves the
     * Gate path inert (the model's policy cannot be resolved without an instance), but a
     * DECLARED `policy:` is still enforced against the resource-class token — a declared
     * policy is never fail-open, even for a persister-less read-only type.
     */
    public function authorize(string $type, Operation $operation, ?object $subject, ?string $server = null): void
    {
        $config = $this->configFor($server, $type);

        // The ability for this operation: a per-op rename, `false` to disable, `true` to
        // document as externally-secured, or the policy-convention default. `??` only
        // coalesces the null "no override" case, so an explicit `false`/`true` is preserved.
        $declared = $config?->ability($operation);
        $ability = $declared ?? $this->defaultAbility($operation);
        if ($ability === false) {
            return;
        }

        // `true` documents the operation as secured (an external firewall/middleware enforces
        // it) and is projected into the OpenAPI document as secured + 401, but the package's
        // Gate does NOT enforce it — so, for enforcement, it is inert.
        if ($ability === true) {
            return;
        }

        // The per-resource operations authorize the loaded/blank instance (so a policy
        // method receives it, e.g. `view($user, $model)`); the collection/create
        // operations authorize the class-string (so `viewAny($user)` / `create($user)`
        // is called without a stray model argument, exactly as
        // `Gate::authorize('viewAny', Model::class)`).
        $carriesInstance = $this->carriesInstance($operation);

        if ($config?->policy !== null) {
            $this->authorizeViaPolicy($config->policy, $ability, $subject, $carriesInstance, $config->subjectClass);

            return;
        }

        // A renamed ability is a declaration, so it is enforced even when nothing defines it
        // (the Gate then denies); only the convention default stays inert without a policy.
        if (\is_string($declared)) {
            $this->authorizeDeclared($type, $ability, $subject, $carriesInstance, $config?->subjectClass);

            return;
        }

        $this->authorizeViaGate($ability, $subject, $carriesInstance);
    }

    /**
     * Abilities a relation's convention name may not take, since they already mean something
     * for the parent type (a relation named `any` would otherwise read as `viewAny`).
     */
    private const array STANDARD_ABILITIES = ['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete'];

    /**
     * The read permission `$relation` declares on `$parent` (ADR 0049): `true` or `false` when it
     * declares one, `null` when it declares none and the related type's own gates apply. A
     * string `security(read:)` is checked against the parent; a bool one documents the
     * relation and allows it; otherwise a `view{Relation}` ability the parent type's policy or
     * the Gate defines is checked against the parent.
     */
    public function relationReadPermission(string $parentType, \haddowg\JsonApi\Resource\Field\RelationInterface $relation, object $parent, ?string $server = null): ?bool
    {
        $read = $relation->securityRead();
        if (\is_bool($read)) {
            return true;
        }

        $ability = \is_string($read) ? $read : $this->conventionReadAbility($parentType, $relation, $parent, $server);

        return $ability === null ? null : $this->allowsAbility($parentType, $ability, $parent, $server);
    }

    /**
     * The `view{Relation}` ability for `$relation` on `$parent`, or `null` when nothing defines
     * it: no method of that name on the type's dedicated policy or the model's Gate policy, and
     * no Gate ability. Only a defined ability declares a permission, because the Gate denies an
     * undefined one.
     */
    public function conventionReadAbility(string $parentType, \haddowg\JsonApi\Resource\Field\RelationInterface $relation, object $parent, ?string $server = null): ?string
    {
        $ability = 'view' . \Illuminate\Support\Str::studly($relation->name());
        if (\in_array($ability, self::STANDARD_ABILITIES, true)) {
            return null;
        }

        /** @var mixed $policy */
        $policy = $this->configFor($server, $parentType)->policy ?? $this->gate->getPolicyFor($parent);
        if ((\is_object($policy) || \is_string($policy)) && \method_exists($policy, $ability)) {
            return $ability;
        }

        return $this->gate->has($ability) ? $ability : null;
    }

    /**
     * Whether the current user passes {@see authorize()} for `$operation` on `$type`.
     */
    public function allows(string $type, Operation $operation, ?object $subject, ?string $server = null): bool
    {
        try {
            $this->authorize($type, $operation, $subject, $server);

            return true;
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            return false;
        }
    }

    /**
     * Whether the current user passes {@see authorizeAbility()} for `$ability` on `$subject`.
     */
    public function allowsAbility(string $type, string $ability, object $subject, ?string $server = null): bool
    {
        try {
            $this->authorizeAbility($type, $ability, $subject, $server);

            return true;
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            return false;
        }
    }

    /**
     * Authorizes an explicit `$ability` against the loaded `$subject` instance, honouring
     * the type's declared `policy:` class exactly as {@see authorize()} does — the seam a
     * per-relation read-security override (`security(read: 'ability')`) gates a related /
     * relationship read through, since that ability is not one of the five CRUD operations.
     */
    public function authorizeAbility(string $type, string $ability, object $subject, ?string $server = null): void
    {
        $config = $this->configFor($server, $type);

        if ($config?->policy !== null) {
            $this->authorizeViaPolicy($config->policy, $ability, $subject, true, $config->subjectClass);

            return;
        }

        $this->authorizeDeclared($type, $ability, $subject, true, $config?->subjectClass);
    }

    /**
     * Authorizes a custom action's declared `ability` (PLAN decision 12) against its
     * subject — the resolved entity for a resource-scope action, or `null` for a
     * collection-scope action (then the ability authorizes the resource-class token, so
     * a `publishAny($user)`-style method / `Gate::define()` closure is called). Honours
     * the type's declared `policy:` class exactly as {@see authorize()} does; throws an
     * {@see \Illuminate\Auth\Access\AuthorizationException} on denial. The action's before
     * gate is enforced here directly (the handler has no event dispatcher yet — the
     * parallel of how the CRUD arms call {@see authorize()} inline).
     */
    public function authorizeAction(string $type, string $ability, ?object $subject, ?string $server = null): void
    {
        $config = $this->configFor($server, $type);
        $carriesInstance = $subject !== null;

        if ($config?->policy !== null) {
            $this->authorizeViaPolicy($config->policy, $ability, $subject, $carriesInstance, $config->subjectClass);

            return;
        }

        $this->authorizeDeclared($type, $ability, $subject, $carriesInstance, $config?->subjectClass);
    }

    /**
     * Whether the current user would pass a custom action's `ability` gate for `$subject`
     * — the boolean twin of {@see authorizeAction()} the ability-aware
     * {@see \haddowg\JsonApiLaravel\Action\ActionLinkContributor} uses to decide whether
     * to render an action's `links` member (so a client never sees a link to an action it
     * could not invoke). An action with no policy / no defined ability is inert, so the
     * link renders — matching invocation, which is likewise inert.
     */
    public function allowsAction(string $type, string $ability, ?object $subject, ?string $server = null): bool
    {
        try {
            $this->authorizeAction($type, $ability, $subject, $server);

            return true;
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            return false;
        }
    }

    /**
     * The overrides `$type` declares on `$server`, or null when its resource there declares
     * none. A null server is the `default` server.
     */
    public function resourceAuthorization(?string $server, string $type): ?ResourceAuthorization
    {
        return $this->configFor($server, $type);
    }

    /**
     * The policy-convention ability `$operation` is checked against when nothing renames it.
     */
    public static function conventionAbility(Operation $operation): string
    {
        return match ($operation) {
            Operation::FetchCollection => 'viewAny',
            Operation::FetchOne => 'view',
            Operation::Create => 'create',
            Operation::Update => 'update',
            Operation::Delete => 'delete',
        };
    }

    /**
     * Whether `$operation` is checked against the loaded resource rather than its class.
     */
    public static function checksInstance(Operation $operation): bool
    {
        return match ($operation) {
            Operation::FetchOne, Operation::Update, Operation::Delete => true,
            Operation::FetchCollection, Operation::Create => false,
        };
    }

    /**
     * The overrides `$type` declares on `$server`, or null when its resource there declares
     * none. A null server is the `default` server.
     */
    private function configFor(?string $server, string $type): ?ResourceAuthorization
    {
        return $this->servers[$server ?? ServerRegistry::DEFAULT_SERVER][$type] ?? null;
    }

    /**
     * The model's Gate-registered policy path for a convention ability nobody declared.
     * It only enforces when the model has a policy or the ability is a defined Gate
     * closure; a type with neither is inert (PLAN decision 7), so the package adds no
     * authorization an application did not ask for. A null subject (a read-only
     * collection with no instance) is inert too: no policy can be resolved without one.
     */
    private function authorizeViaGate(string $ability, ?object $subject, bool $carriesInstance): void
    {
        $gateSubject = $carriesInstance ? $subject : ($subject !== null ? $subject::class : null);
        if ($gateSubject === null) {
            return;
        }

        if ($this->gate->getPolicyFor($gateSubject) === null && !$this->gate->has($ability)) {
            return;
        }

        $this->gate->authorize($ability, $gateSubject);
    }

    /**
     * Enforces an ability the author declared: a renamed operation ability, a relation's
     * `security(read:/mutate:)` or a custom action's `ability`. Unlike the convention
     * default it never goes inert. When no policy or `Gate::define()` answers for it, the
     * Gate denies, so a declaration whose policy was never registered fails closed rather
     * than letting every caller through. A class-level check with no instance uses the
     * resource class as its subject so a class-registered policy or a defined ability is
     * still consulted.
     *
     * @param class-string|null $subjectClass
     */
    private function authorizeDeclared(string $type, string $ability, ?object $subject, bool $carriesInstance, ?string $subjectClass): void
    {
        $gateSubject = $carriesInstance ? $subject : ($subject !== null ? $subject::class : $subjectClass);
        if ($gateSubject === null) {
            throw new \LogicException(\sprintf(
                'The "%s" ability is declared for the "%s" type, but no subject or subject class could be resolved '
                . 'to enforce it, so the request is refused rather than allowed unchecked.',
                $ability,
                $type,
            ));
        }

        $this->gate->authorize($ability, $gateSubject);
    }

    /**
     * The dedicated `policy:` class path (PLAN decision 7): map the declared policy onto
     * a throwaway Gate scoped to the current request, then authorize through it. This
     * reuses the Gate's `before()` handling, guest (nullable-first-parameter) resolution
     * and class-string argument shifting, while leaving the application's own
     * `Gate::policy()` mapping untouched — the cloned Gate's policy map is copy-on-write,
     * so mapping the class here never mutates the shared instance, and its inherited user
     * resolver still resolves the current request's user.
     *
     * A declared policy is **always enforced** — never fail-open. A class-level operation
     * (`viewAny`/`create`) whose subject is null (a read-only type mints no list instance)
     * falls back to the resource-class token, so `viewAny($user)`/`create($user)` still
     * run: the class-level policy ability never receives the instance anyway, so the token
     * is purely the scoping key the throwaway Gate resolves the policy through. If a policy
     * is declared but no class token can be resolved at all, that is a wiring fault, so it
     * fails loud rather than silently skipping the check.
     */
    private function authorizeViaPolicy(string $policy, string $ability, ?object $subject, bool $carriesInstance, ?string $subjectClass): void
    {
        // An instance-carrying op (view/update/delete) authorizes the loaded model itself,
        // so the policy method receives it (e.g. `view($user, $model)`); a class-level op
        // (viewAny/create) authorizes a class-string token (so `viewAny($user)` is called
        // without a stray argument).
        $token = $carriesInstance ? $subject : ($subject !== null ? $subject::class : $subjectClass);

        if ($token === null) {
            throw new \LogicException(\sprintf(
                'A dedicated JSON:API policy (%s) is declared for the "%s" ability, but no subject or subject '
                . 'class could be resolved to enforce it — refusing to fail open. This is a wiring fault: the '
                . 'authorization config should carry the resource class as the class-level subject token.',
                $policy,
                $ability,
            ));
        }

        $scoped = clone $this->gate;
        $scoped->policy(\is_object($token) ? $token::class : $token, $policy);

        $scoped->authorize($ability, $token);
    }

    /**
     * The policy-convention ability name for an operation.
     */
    private function defaultAbility(Operation $operation): string
    {
        return self::conventionAbility($operation);
    }

    /**
     * Whether the operation authorizes a loaded/blank instance (true) or its
     * class-string (false) — the per-resource operations carry the instance; the
     * collection and create operations carry the class.
     */
    private function carriesInstance(Operation $operation): bool
    {
        return self::checksInstance($operation);
    }
}
