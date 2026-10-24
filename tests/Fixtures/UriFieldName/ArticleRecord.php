<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UriFieldName;

final class ArticleRecord
{
    /**
     * @param list<CommentRecord> $remarks
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public array $remarks = [],
        public ?AuthorRecord $writer = null,
    ) {}
}
