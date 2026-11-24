<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners;

/**
 * The in-memory twin of {@see MoVideo}.
 */
final class MoVideoRecord extends MoOwnerRecord
{
    /**
     * @var list<MoNoteRecord>
     */
    public array $authoredNotes = [];
}
