<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Authorization;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\RelationAuthorizerInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * Answers core's questions about the relation data it reaches outside the relation's own
 * endpoints (ADR 0044), with the same policy rules the handler applies at those endpoints.
 *
 * A rendered relationship carries its data only when the caller passes the relation's
 * `security(read:)` ability against the parent, the rule the related and relationship reads
 * apply; a relation without a string ability inherits the parent's read, which the caller
 * already passed to see the parent. A to-many whose related type the caller may not list
 * renders no data either (ADR 0045). A flattened `on()` write needs the
 * `update` ability (or its rename) of the related type on the row it writes onto.
 *
 * One instance serves one server. The server is resolved lazily, because the server is built
 * with this authorizer.
 */
final class RelationAuthorizer implements RelationAuthorizerInterface
{
    /**
     * The type declaring each relation instance of the server, filled on first use.
     *
     * @var \WeakMap<RelationInterface, string>|null
     */
    private ?\WeakMap $declaringTypes = null;

    /**
     * @param \Closure(): Server $server
     */
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly \Closure $server,
        private readonly ?ViewableRows $rows = null,
    ) {}

    public function canReadRelationship(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): bool
    {
        // A permission the relation declares replaces the related type's own gates (ADR 0049).
        if ($this->rows !== null && \is_object($model)) {
            return $this->rows->relationPermission($model, $relation) ?? !$this->refusesList($relation);
        }

        $ability = $relation->securityRead();
        if (\is_string($ability) && (!\is_object($model) || !$this->authorizer->allowsAbility($this->declaringType($relation) ?? '', $ability, $model))) {
            return false;
        }

        return !$this->refusesList($relation);
    }

    public function canWriteRelatedAttribute(mixed $owner, RelationInterface $relation, FieldInterface $field, mixed $model, JsonApiRequestInterface $request): bool
    {
        if (!\is_object($owner)) {
            return false;
        }

        $type = $this->ownerType($owner, $relation);

        return $type !== null && $this->authorizer->allows($type, Operation::Update, $owner);
    }

    /**
     * Whether `$relation` is a to-many listing a type the caller may not list, so its data is
     * withheld whole (ADR 0045). A polymorphic to-many is withheld when any member type is.
     */
    private function refusesList(RelationInterface $relation): bool
    {
        if ($this->rows === null || !$relation->isToMany()) {
            return false;
        }

        foreach ($relation->relatedTypes() as $type) {
            if (!$this->rows->mayList($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The type of the related row a flattened write lands on: the relation's only type, or
     * for a polymorphic relation the declared type whose serializer claims the row.
     */
    private function ownerType(object $owner, RelationInterface $relation): ?string
    {
        $types = $relation->relatedTypes();
        if (\count($types) === 1) {
            return $types[0];
        }

        $server = ($this->server)();
        foreach ($types as $type) {
            if ($server->hasSerializerFor($type) && $server->serializerFor($type)->getType($owner) === $type) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The type whose resource declares `$relation`, which decides the policy its read ability
     * resolves through. Null when no resource of the server declares this instance, in which
     * case the ability goes through the Gate alone.
     */
    private function declaringType(RelationInterface $relation): ?string
    {
        if ($this->declaringTypes === null) {
            /** @var \WeakMap<RelationInterface, string> $map */
            $map = new \WeakMap();
            $server = ($this->server)();
            foreach ($server->resources()->types() as $type) {
                if (!$server->hasResourceFor($type)) {
                    continue;
                }
                foreach ($server->resourceFor($type)->allFields() as $field) {
                    if ($field instanceof RelationInterface) {
                        $map[$field] = $type;
                    }
                }
            }
            $this->declaringTypes = $map;
        }

        return $this->declaringTypes[$relation] ?? null;
    }
}
