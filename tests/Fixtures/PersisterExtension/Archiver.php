<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PersisterExtension;

/**
 * An application service that archives a note instead of deleting it.
 */
final class Archiver
{
    public function archive(Note $note): void
    {
        $note->archived_at = '2026-01-01 00:00:00';
        $note->save();
    }

    public function initialStatus(): string
    {
        return 'draft';
    }
}
