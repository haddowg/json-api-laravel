<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Authorization;

use haddowg\JsonApiLaravel\Operation\Operation;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * The policy-first authorization gate (PLAN decision 7), invoked by the
 * {@see \haddowg\JsonApiLaravel\Operation\CrudOperationHandler} at each write/read
 * lifecycle point with the loaded model in hand, before persistence:
 * list→`viewAny`, read→`view`, create→`create`, update→`update`, delete→`delete`.
 *
 * Resolution order per type (from its
 * {@see \haddowg\JsonApiLaravel\Attribute\AsJsonApiResource} attribute):
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
     * @param array<string, ResourceAuthorization> $config the per-type authorization overrides, keyed by JSON:API type
     */
    public function __construct(
        private readonly Gate $gate,
        private readonly array $config,
    ) {}

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
    public function authorize(string $type, Operation $operation, ?object $subject): void
    {
        $config = $this->config[$type] ?? null;

        // The ability for this operation: a per-op rename, `false` to disable, or the
        // policy-convention default. `??` only coalesces the null "no override" case,
        // so an explicit `false` (disable) is preserved.
        $ability = $config?->ability($operation) ?? $this->defaultAbility($operation);
        if ($ability === false) {
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

        $this->authorizeViaGate($ability, $subject, $carriesInstance);
    }

    /**
     * Authorizes an explicit `$ability` against the loaded `$subject` instance, honouring
     * the type's declared `policy:` class exactly as {@see authorize()} does — the seam a
     * per-relation read-security override (`security(read: 'ability')`) gates a related /
     * relationship read through, since that ability is not one of the five CRUD operations.
     */
    public function authorizeAbility(string $type, string $ability, object $subject): void
    {
        $config = $this->config[$type] ?? null;

        if ($config?->policy !== null) {
            $this->authorizeViaPolicy($config->policy, $ability, $subject, true, $config->subjectClass);

            return;
        }

        $this->authorizeViaGate($ability, $subject, true);
    }

    /**
     * The model's Gate-registered policy (or a defined ability) path — the inert
     * default. Only enforces when the model actually has a policy OR the ability is a
     * defined Gate closure; a type with neither is inert (PLAN decision 7), so the
     * package adds no authorization an application did not ask for.
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
        return match ($operation) {
            Operation::FetchCollection => 'viewAny',
            Operation::FetchOne => 'view',
            Operation::Create => 'create',
            Operation::Update => 'update',
            Operation::Delete => 'delete',
        };
    }

    /**
     * Whether the operation authorizes a loaded/blank instance (true) or its
     * class-string (false) — the per-resource operations carry the instance; the
     * collection and create operations carry the class.
     */
    private function carriesInstance(Operation $operation): bool
    {
        return match ($operation) {
            Operation::FetchOne, Operation::Update, Operation::Delete => true,
            Operation::FetchCollection, Operation::Create => false,
        };
    }
}
