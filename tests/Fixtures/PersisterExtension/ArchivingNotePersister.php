<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PersisterExtension;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;

/**
 * Builds new notes with the status the service picks and archives a deleted note through the
 * service, inheriting every other write from the reference persister.
 */
final class ArchivingNotePersister extends EloquentDataPersister
{
    public function __construct(private readonly Archiver $archiver)
    {
        parent::__construct(['notes' => Note::class]);
    }

    public function instantiate(string $type): object
    {
        return new Note(['status' => $this->archiver->initialStatus()]);
    }

    public function delete(string $type, object $entity): void
    {
        \assert($entity instanceof Note);
        $this->archiver->archive($entity);
    }
}
