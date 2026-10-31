<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Authorization;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApiLaravel\Server\ServerRegistry;

/**
 * The related type's own read gates for rows another type renders. A single related
 * row passes its type's `view` gate, the check `GET /{type}/{id}` applies to it, or reads as
 * absent. A to-many passes its related type's collection gate, the check `GET /{type}` applies,
 * or is withheld whole; which rows it lists is left to the query.
 *
 * Each gate is the one the bound server's resource declares for the type. Answers are memoized
 * for the operation being handled.
 */
final class ViewableRows
{
    private ?Server $server = null;

    private string $serverName = ServerRegistry::DEFAULT_SERVER;

    /**
     * @var \WeakMap<object, array<string, bool>>
     */
    private \WeakMap $memo;

    /**
     * @var array<string, bool>
     */
    private array $listable = [];

    /**
     * @var \WeakMap<object, array<string, bool|null>>
     */
    private \WeakMap $permissions;

    /**
     * @var \WeakMap<RelationInterface, string>|null
     */
    private ?\WeakMap $declaringTypes = null;

    private ?object $mappedRegistry = null;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly DataPersisterRegistry $persisters,
    ) {
        $this->memo = new \WeakMap();
        $this->permissions = new \WeakMap();
    }

    /**
     * Sets the server whose serializers type a polymorphic relation's rows and whose resources'
     * declarations the gates read, and drops every memoized answer. A null name is the
     * `default` server.
     */
    public function bind(?Server $server, ?string $serverName = null): void
    {
        $this->server = $server;
        $this->serverName = $serverName ?? ServerRegistry::DEFAULT_SERVER;
        $this->forget();
    }

    /**
     * Drops every memoized answer, so a row a write just changed is decided again.
     */
    public function forget(): void
    {
        $this->memo = new \WeakMap();
        $this->permissions = new \WeakMap();
        $this->listable = [];
    }

    /**
     * The read permission `$relation` declares on `$parent`: `true` or `false`, or
     * `null` when it declares none and the related type's own gates apply. Memoized per parent
     * and relation for the operation.
     */
    public function relationPermission(object $parent, RelationInterface $relation, ?string $parentType = null): ?bool
    {
        $answers = $this->permissions[$parent] ?? [];
        $name = $relation->name();
        if (!\array_key_exists($name, $answers)) {
            $answers[$name] = $this->authorizer->relationReadPermission($parentType ?? $this->declaringType($relation) ?? '', $relation, $parent, $this->serverName);
            $this->permissions[$parent] = $answers;
        }

        return $answers[$name];
    }

    /**
     * Whether the caller may see `$row`, reached through `$relation`, as `$type` when the caller
     * knows it, otherwise as the type `$relation` resolves for the row.
     */
    public function allows(object $row, RelationInterface $relation, ?string $type = null): bool
    {
        $type ??= $this->typeOf($row, $relation);
        if ($type === null) {
            return true;
        }

        $answers = $this->memo[$row] ?? [];
        if (!\array_key_exists($type, $answers)) {
            $answers[$type] = $this->authorizer->allows($type, Operation::FetchOne, $row, $this->serverName);
            $this->memo[$row] = $answers;
        }

        return $answers[$type];
    }

    /**
     * Whether the caller passes `$type`'s collection gate, the `viewAny` check (or its rename
     * or dedicated policy) `GET /{type}` runs. Asked once per type per operation.
     */
    public function mayList(string $type): bool
    {
        if (!\array_key_exists($type, $this->listable)) {
            $subject = $this->persisters->supportsType($type) ? $this->persisters->forType($type)->instantiate($type) : null;
            $this->listable[$type] = $this->authorizer->allows($type, Operation::FetchCollection, $subject, $this->serverName);
        }

        return $this->listable[$type];
    }

    /**
     * Refuses a list of `$type` exactly as `GET /{type}` does for this caller.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function authorizeList(string $type): void
    {
        $subject = $this->persisters->supportsType($type) ? $this->persisters->forType($type)->instantiate($type) : null;
        $this->authorizer->authorize($type, Operation::FetchCollection, $subject, $this->serverName);
    }

    /**
     * The type whose resource declares `$relation` on the bound server, or `null` when none does.
     */
    private function declaringType(RelationInterface $relation): ?string
    {
        if ($this->server === null) {
            return null;
        }

        $registry = $this->server->resources();
        if ($this->declaringTypes === null || $this->mappedRegistry !== $registry) {
            /** @var \WeakMap<RelationInterface, string> $map */
            $map = new \WeakMap();
            foreach ($registry->types() as $type) {
                if (!$registry->hasResourceFor($type)) {
                    continue;
                }
                foreach ($registry->resourceFor($type)->allFields() as $field) {
                    if ($field instanceof RelationInterface) {
                        $map[$field] = $type;
                    }
                }
            }
            $this->declaringTypes = $map;
            $this->mappedRegistry = $registry;
        }

        return $this->declaringTypes[$relation] ?? null;
    }

    /**
     * The type `$row` renders as: the relation's only type, or for a polymorphic relation the
     * declared type whose serializer claims it. Null when no declared type does, in which case
     * nothing can render the row anyway.
     */
    private function typeOf(object $row, RelationInterface $relation): ?string
    {
        $types = $relation->relatedTypes();
        if (\count($types) === 1) {
            return $types[0];
        }

        if ($this->server === null) {
            return null;
        }

        $serializer = $relation->resolveSerializer($row, $this->server);

        return $serializer?->getType($row);
    }
}
