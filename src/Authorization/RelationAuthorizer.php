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
 * already passed to see the parent. A to-many holding a row the caller may not `view`, when
 * core reads it without the linkage seam that leaves such rows out, renders no data either
 * (ADR 0045). A flattened `on()` write needs the
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
        $ability = $relation->securityRead();
        if (\is_string($ability) && (!\is_object($model) || !$this->authorizer->allowsAbility($this->declaringType($relation) ?? '', $ability, $model))) {
            return false;
        }

        return !$this->hidesMember($model, $relation, $request);
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
     * Whether a to-many on `$model` holds a member the caller may not `view` that the linkage seam
     * can't leave out (ADR 0045), so its data is withheld as a whole instead. Core reads a
     * polymorphic to-many off the model without asking the seam, and reads a lazy to-many the
     * storage hadn't loaded only once it is included. A lazy relation that isn't included renders
     * no data, so it isn't read.
     */
    private function hidesMember(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): bool
    {
        if ($this->rows === null || !\is_object($model) || !$relation->isToMany()) {
            return false;
        }

        $deferred = $relation->emitsDataOnlyWhenLoaded() && $relation->includesLinks()
            && ($this->server)()->resources()->relationshipLoadState()?->isRelationshipLoaded($model, $relation) === false;
        $polymorphic = \count($relation->relatedTypes()) > 1;

        if ((!$polymorphic && !$deferred) || ($deferred && !$this->mayBeIncluded($model, $relation, $request))) {
            return false;
        }

        $members = $relation->readValue($model, $request);

        return \is_iterable($members) && $this->rows->filter($members, $relation) !== null;
    }

    /**
     * Whether `$relation` may be included on `$model` in this request: a requested include path
     * names it, or its resource includes it by default.
     */
    private function mayBeIncluded(object $model, RelationInterface $relation, JsonApiRequestInterface $request): bool
    {
        $name = $relation->name();
        foreach ($request->getIncludePaths() as $path) {
            if (\in_array($name, \explode('.', $path), true)) {
                return true;
            }
        }

        $type = $this->declaringType($relation);
        $server = ($this->server)();

        return $type !== null && $server->hasSerializerFor($type)
            && \in_array($name, $server->serializerFor($type)->getDefaultIncludedRelationships($model), true);
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
