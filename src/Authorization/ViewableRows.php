<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Authorization;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * Decides whether a related row may be shown for another type, by the related type's own
 * `view` gate: the check `GET /{type}/{id}` applies to the same row (ADR 0045). A row the gate
 * refuses reads as absent, so it is left out of linkage, `included` and the related endpoint.
 *
 * Answers are memoized per row and type for the operation being handled; the policy runs in
 * memory, so a page of rows costs no queries beyond what the policy itself does.
 */
final class ViewableRows
{
    private ?Server $server = null;

    /**
     * @var \WeakMap<object, array<string, bool>>
     */
    private \WeakMap $memo;

    public function __construct(private readonly Authorizer $authorizer)
    {
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
     * The rows of `$rows` the caller may see, or `null` when that is all of them, so a caller
     * can keep the original value untouched.
     *
     * @param iterable<mixed> $rows
     *
     * @return list<mixed>|null
     */
    public function filter(iterable $rows, RelationInterface $relation): ?array
    {
        $kept = [];
        $removed = false;
        foreach ($rows as $row) {
            if (\is_object($row) && !$this->allows($row, $relation)) {
                $removed = true;

                continue;
            }
            $kept[] = $row;
        }

        return $removed ? $kept : null;
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
