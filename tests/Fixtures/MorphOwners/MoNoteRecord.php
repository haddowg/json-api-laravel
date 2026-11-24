<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners;

/**
 * The in-memory twin of {@see MoNote}.
 */
final class MoNoteRecord
{
    public function __construct(
        public string $id = '',
        public string $body = '',
        public ?string $owner_type = null,
        public ?string $owner_id = null,
        public ?MoOwnerRecord $author = null,
    ) {}
}
