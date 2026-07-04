<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Mutations;

/**
 * The in-memory `posts` POPO twin of {@see Post}: the same relation surface as public
 * members (the relation's `column ?? name`), read + written by the in-memory
 * provider/persister. A to-one holds the related object (or null); a to-many a list of
 * related objects. The polymorphic `feature` holds an {@see AuthorDomain} OR a
 * {@see TagDomain}.
 */
final class PostDomain
{
    /**
     * @param list<TagDomain>          $tags
     * @param list<TagDomain>          $pinnedTags
     * @param AuthorDomain|TagDomain|null $feature
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?AuthorDomain $author = null,
        public ?AuthorDomain $sponsor = null,
        public ?AuthorDomain $moderator = null,
        public AuthorDomain|TagDomain|null $feature = null,
        public array $tags = [],
        public array $pinnedTags = [],
    ) {}
}
