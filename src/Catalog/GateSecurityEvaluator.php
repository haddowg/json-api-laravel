<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Catalog;

use haddowg\JsonApi\Catalog\OperationDefinition;
use haddowg\JsonApi\Catalog\SecurityEvaluatorInterface;
use haddowg\JsonApi\Catalog\SecurityVerdict;
use haddowg\JsonApiLaravel\Authorization\Authorizer;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * Judges a caller against an operation's Gate ability without performing it.
 *
 * The ability is looked up where the {@see Authorizer} would find it: the type's `policy:`
 * class, else the model's Gate policy, else a `Gate::define()` closure. When the policy
 * method or closure needs an argument after the user, it needs the resource, so the verdict
 * is {@see SecurityVerdict::Depends}. A policy with a `before()` method is `Depends` too,
 * whether it is the type's policy or the class a `Gate::define()` ability points at,
 * since `before()` may decide differently for each resource. Otherwise the ability is
 * inspected with `Gate::forUser()->inspect()` against the resource class, and the answer is
 * `Allowed` or `Denied`.
 *
 * An operation with no rule, or one declared public, is `Allowed`. One documented as
 * secured by something outside the package (`true`) is `Depends`, since the package cannot
 * see that check. A declared ability nothing defines is `Denied`, because the Gate denies
 * it when the operation runs.
 */
final class GateSecurityEvaluator implements SecurityEvaluatorInterface
{
    /**
     * @param \Closure(): array<string, class-string> $models the `type → model class` map, read on first use
     */
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly Gate $gate,
        private readonly \Closure $models,
    ) {}

    public function evaluate(OperationDefinition $operation, ?object $user = null): SecurityVerdict
    {
        $ability = $operation->security;
        if ($ability === null || $ability === false) {
            return SecurityVerdict::Allowed;
        }
        if ($ability === true) {
            return SecurityVerdict::Depends;
        }
        if ($operation->type === null) {
            return SecurityVerdict::Depends;
        }

        $server = $operation->server !== '' ? $operation->server : ServerRegistry::DEFAULT_SERVER;
        $config = $this->authorizer->resourceAuthorization($server, $operation->type);
        $declaredPolicy = $config?->policy;
        $model = ($this->models)()[$operation->type] ?? null;
        $subject = $model ?? $config?->subjectClass;

        $gate = $this->gate;
        $policy = $declaredPolicy;
        if ($declaredPolicy !== null && $subject !== null) {
            // The Authorizer maps a declared policy onto a throwaway Gate, leaving the app's
            // own mapping untouched, and so does this.
            $gate = clone $this->gate;
            $gate->policy($subject, $declaredPolicy);
        } elseif ($model !== null) {
            $found = $this->gate->getPolicyFor($model);
            $policy = \is_object($found) || \is_string($found) ? $found : null;
        }

        if ($policy !== null && \method_exists($policy, $ability)) {
            if (\method_exists($policy, 'before') || self::needsMoreThanTheUser(new \ReflectionMethod($policy, $ability))) {
                return SecurityVerdict::Depends;
            }
        } elseif ($this->gate->has($ability)) {
            $callback = $this->gate->abilities()[$ability] ?? null;
            if ($callback instanceof \Closure && self::abilityDepends($callback)) {
                return SecurityVerdict::Depends;
            }
        }

        $arguments = $subject !== null ? [$subject] : [];

        return $gate->forUser($user)->inspect($ability, $arguments)->allowed()
            ? SecurityVerdict::Allowed
            : SecurityVerdict::Denied;
    }

    /**
     * Whether a `Gate::define()` ability needs the resource. An ability defined as a
     * `[Policy::class, 'method']` pair or a `Policy@method` string is wrapped in a closure
     * that takes any arguments and calls the policy's `before()` first, so the method itself
     * and the policy are read instead.
     */
    private static function abilityDepends(\Closure $callback): bool
    {
        $function = new \ReflectionFunction($callback);
        $target = $function->getStaticVariables()['callback'] ?? null;
        if (!\is_string($target)) {
            return self::needsMoreThanTheUser($function);
        }

        [$class, $method] = \str_contains($target, '@') ? \explode('@', $target, 2) : [$target, '__invoke'];
        if (!\class_exists($class) || !\method_exists($class, $method)) {
            return false;
        }

        return \method_exists($class, 'before') || self::needsMoreThanTheUser(new \ReflectionMethod($class, $method));
    }

    /**
     * Whether the policy method or ability closure requires an argument after the user,
     * which only the resource the operation acts on can supply.
     */
    private static function needsMoreThanTheUser(\ReflectionFunctionAbstract $function): bool
    {
        return $function->getNumberOfRequiredParameters() > 1;
    }
}
