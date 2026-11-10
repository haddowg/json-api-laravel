<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataPersister;

use haddowg\JsonApi\Collection\LinkRows\LinkRowWritePlan;
use haddowg\JsonApi\Resource\Field\MorphToMany;

/**
 * A persister that writes the link rows of a `MorphToMany` declared with `identifiedByRows()`
 * from core's {@see LinkRowWritePlan}: its deletes, then its temporary positions, then its
 * updates, then its inserts, each pass finished before the next.
 */
interface WritesLinkRowsInterface
{
    /**
     * Applies `$plan` to `$parent`'s link rows of `$relation`. With `$flush` the passes run in
     * their own transaction, else inline under the write that embeds them.
     */
    public function writeLinkRows(string $type, object $parent, MorphToMany $relation, LinkRowWritePlan $plan, bool $flush = true): object;
}
