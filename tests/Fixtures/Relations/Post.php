<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Relations;

/**
 * A blog post — the parent POPO carrying every 3a relation cardinality the in-memory
 * witness exercises in isolation from the shared music catalog:
 *  - `$author` — a monomorphic to-one ({@see Author}), null for an unowned post;
 *  - `$tags` — a monomorphic to-many ({@see Tag}), the `belongsToMany` linkage whose
 *    in-memory pivot meta is empty (the documented boundary);
 *  - `$feature` — a **polymorphic** to-one (an {@see Author} OR a {@see Tag}), null when unset;
 *  - `$related` — a **polymorphic** to-many (a mixed list of {@see Author}/{@see Tag}).
 *
 * The property names are the relation `column ?? name`, read by core's `Accessor`.
 */
final class Post
{
    /**
     * @param list<Tag>            $tags
     * @param Author|Tag|null      $feature
     * @param list<Author|Tag>     $related
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?Author $author = null,
        public array $tags = [],
        public Author|Tag|null $feature = null,
        public array $related = [],
    ) {}
}
