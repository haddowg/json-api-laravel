<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataPersister;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * An optional {@see DataPersisterInterface} capability for relations where a related row has
 * at most one parent (an inverse foreign key, such as a has-many). Before a write adds such a
 * row to a parent, the handler asks which incoming rows another parent holds now, and checks
 * the client may update that parent (or the row itself) before moving it. A persister that
 * does not implement this moves rows unchecked.
 */
interface ReportsCurrentOwners
{
    /**
     * The linkage members that a parent other than `$parent` currently holds through
     * `$relation`, each with that parent. `owner` and `ownerType` are null when the owner
     * can't be resolved to a JSON:API resource; the handler then checks the member alone. A
     * relation whose related rows can be shared between parents returns `[]`.
     *
     * @return list<array{memberType: string, member: object, ownerType: ?string, owner: ?object}>
     */
    public function currentOwners(string $type, object $parent, RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage): array;
}
