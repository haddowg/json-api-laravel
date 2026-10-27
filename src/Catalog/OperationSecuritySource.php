<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Catalog;

use haddowg\JsonApi\Catalog\OperationSecurity;
use haddowg\JsonApi\Catalog\OperationSecuritySourceInterface;
use haddowg\JsonApi\Catalog\SecurityLevel;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApiLaravel\Action\ActionRegistry;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Authorization\Authorizer;
use haddowg\JsonApiLaravel\Operation\Operation;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Str;

/**
 * Tells the operation catalogue which Gate ability guards each operation, read from the
 * same declarations the {@see Authorizer} enforces.
 *
 * - A CRUD operation is guarded by its renamed ability, or by the policy-convention one
 *   (`viewAny`, `view`, `create`, `update`, `delete`) when the type has a `policy:`, the
 *   model has a Gate policy or the Gate defines the ability. With none of them the check is
 *   inert and the operation reports no rule. An ability override of `false` is the boolean
 *   `false`, and `true` (secured by something outside the package) is the boolean `true`.
 * - A related or relationship read is guarded by the relation's `security(read:)` ability,
 *   else a `view{Relation}` ability the policy or the Gate defines, else the parent's
 *   `fetchOne` rule. `read: false` is public.
 * - A relationship mutation is guarded by the relation's `security(mutate:)` ability, else
 *   the parent's `update` rule. `mutate: false` is public.
 * - A custom action is guarded by its `ability`, or nothing.
 *
 * A rule that receives the loaded resource is {@see SecurityLevel::Entity}; one checked
 * against the resource class is {@see SecurityLevel::Type}.
 */
final class OperationSecuritySource implements OperationSecuritySourceInterface
{
    /** Abilities a relation's convention name may not take, as {@see Authorizer} rules. */
    private const array STANDARD_ABILITIES = ['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete'];

    /**
     * @param \Closure(): array<string, class-string> $models the `type → model class` map, read on first use
     */
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ActionRegistry $actions,
        private readonly Gate $gate,
        private readonly \Closure $models,
    ) {}

    public function securityOf(string $server, ?TypeMetadataInterface $type, OperationKey $key): OperationSecurity
    {
        if ($type === null) {
            return OperationSecurity::none();
        }

        $name = $type->type();
        $crud = $key->kind->operationType();
        if ($crud !== null) {
            return $this->crud($server, $name, Operation::from($crud->value));
        }

        return match ($key->kind) {
            OperationKind::FetchRelated, OperationKind::FetchRelationship => $this->relationRead($server, $type, (string) $key->relation),
            OperationKind::UpdateRelationship, OperationKind::AddRelationship, OperationKind::RemoveRelationship => $this->relationMutate($server, $type, (string) $key->relation),
            OperationKind::Action => $this->action($server, $name, $key),
            default => OperationSecurity::none(),
        };
    }

    private function crud(string $server, string $type, Operation $operation): OperationSecurity
    {
        $declared = $this->authorizer->resourceAuthorization($server, $type)?->ability($operation);
        if (\is_bool($declared)) {
            return new OperationSecurity($declared, $declared ? SecurityLevel::Type : SecurityLevel::None);
        }

        $ability = $declared ?? Authorizer::conventionAbility($operation);
        if ($declared === null && !$this->defines($server, $type, $ability, true)) {
            return OperationSecurity::none();
        }

        return new OperationSecurity($ability, Authorizer::checksInstance($operation) ? SecurityLevel::Entity : SecurityLevel::Type);
    }

    private function relationRead(string $server, TypeMetadataInterface $type, string $relation): OperationSecurity
    {
        $read = $this->relation($type, $relation)?->securityRead();
        if ($read === false) {
            return new OperationSecurity(false);
        }
        if (\is_string($read)) {
            return new OperationSecurity($read, SecurityLevel::Entity);
        }

        $convention = 'view' . Str::studly($relation);
        if ($read === null && !\in_array($convention, self::STANDARD_ABILITIES, true) && $this->defines($server, $type->type(), $convention, false)) {
            return new OperationSecurity($convention, SecurityLevel::Entity);
        }

        return $this->crud($server, $type->type(), Operation::FetchOne);
    }

    private function relationMutate(string $server, TypeMetadataInterface $type, string $relation): OperationSecurity
    {
        $mutate = $this->relation($type, $relation)?->securityMutate();
        if ($mutate === false) {
            return new OperationSecurity(false);
        }
        if (\is_string($mutate)) {
            return new OperationSecurity($mutate, SecurityLevel::Entity);
        }

        return $this->crud($server, $type->type(), Operation::Update);
    }

    private function action(string $server, string $type, OperationKey $key): OperationSecurity
    {
        $scope = $key->actionScope === null ? null : ActionScope::{$key->actionScope->name};
        $descriptor = $scope === null ? null : $this->actions->descriptorFor($server, $type, $scope, (string) $key->actionPath);
        if ($descriptor === null || $descriptor->ability === null) {
            return OperationSecurity::none();
        }

        return new OperationSecurity($descriptor->ability, $scope === ActionScope::Resource ? SecurityLevel::Entity : SecurityLevel::Type);
    }

    private function relation(TypeMetadataInterface $type, string $name): ?RelationMetadataInterface
    {
        foreach ($type->relations() as $relation) {
            if ($relation->name() === $name) {
                return $relation;
            }
        }

        return null;
    }

    /**
     * Whether a convention ability is enforced rather than inert: the type declares a
     * `policy:` (which enforces every CRUD ability, and a relation's only when it has the
     * method), the model's Gate policy has the method, or the Gate defines the ability.
     */
    private function defines(string $server, string $type, string $ability, bool $declaredPolicyEnforcesAll): bool
    {
        $policy = $this->authorizer->resourceAuthorization($server, $type)?->policy;
        if ($policy !== null && ($declaredPolicyEnforcesAll || \method_exists($policy, $ability))) {
            return true;
        }

        $model = ($this->models)()[$type] ?? null;
        $gatePolicy = $model !== null ? $this->gate->getPolicyFor($model) : null;
        if ((\is_object($gatePolicy) || \is_string($gatePolicy)) && \method_exists($gatePolicy, $ability)) {
            return true;
        }

        return $this->gate->has($ability);
    }
}
