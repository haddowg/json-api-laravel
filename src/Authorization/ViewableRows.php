<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Authorization;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The related type's own read gates for rows another type renders (ADR 0045). A single related
 * row passes its type's `view` gate, the check `GET /{type}/{id}` applies to it, or reads as
 * absent. A to-many passes its related type's collection gate, the check `GET /{type}` applies,
 * or is withheld whole; which rows it lists is left to the query.
 *
 * Answers are memoized for the operation being handled.
 */
final class ViewableRows
{
    private ?Server $server = null;

    /**
     * @var \WeakMap<object, array<string, bool>>
     */
    private \WeakMap $memo;

    /**
     * @var array<string, bool>
     */
    private array $listable = [];

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly DataPersisterRegistry $persisters,
    ) {
        $this->memo = new \WeakMap();
    }

    /**
     * Sets the server whose serializers type a polymorphic relation's rows, and drops every
     * memoized answer.
     */
    public function bind(?Server $server): void
    {
        $this->server = $server;
        $this->forget();
    }

    /**
     * Drops every memoized answer, so a row a write just changed is decided again.
     */
    public function forget(): void
    {
        $this->memo = new \WeakMap();
        $this->listable = [];
    }

    /**
     * Whether the caller may see `$row`, reached through `$relation`.
     */
    public function allows(object $row, RelationInterface $relation): bool
    {
        $type = $this->typeOf($row, $relation);
        if ($type === null) {
            return true;
        }

        $answers = $this->memo[$row] ?? [];
        if (!\array_key_exists($type, $answers)) {
            $answers[$type] = $this->authorizer->allows($type, Operation::FetchOne, $row);
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
            $this->listable[$type] = $this->authorizer->allows($type, Operation::FetchCollection, $subject);
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
        $this->authorizer->authorize($type, Operation::FetchCollection, $subject);
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
