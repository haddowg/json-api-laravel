<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Mutations;

/**
 * The in-memory `authors` POPO twin of {@see Author}: the to-one target and the parent of
 * the `posts` to-many (the in-memory HasMany analogue — the persister sets the parent's
 * `$posts` list rather than moving a foreign key).
 */
final class AuthorDomain
{
    /**
     * @param list<PostDomain> $posts
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $posts = [],
    ) {}
}
