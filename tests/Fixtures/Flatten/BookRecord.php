<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Flatten;

/**
 * The in-memory twin of {@see Book}.
 */
final class BookRecord
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?AuthorRecord $author = null,
        public ?AuthorRecord $editor = null,
    ) {}
}
