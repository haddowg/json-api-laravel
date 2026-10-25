<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization;

final class DocumentRecord
{
    /**
     * @param list<NoteRecord> $notes
     * @param list<NoteRecord> $revisions
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public array $notes = [],
        public array $revisions = [],
    ) {}
}
