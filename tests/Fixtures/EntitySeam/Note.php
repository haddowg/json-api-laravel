<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\EntitySeam;

/**
 * A plain domain object for the post-hydration entity-constraint seam fixture: a note
 * whose `title` must be unique across the in-memory store, enforced by a custom
 * {@see UniqueNoteTitle} {@see \haddowg\JsonApiLaravel\Validation\EntityConstraintInterface}
 * validated against the hydrated entity (not the request document).
 */
final class Note
{
    public function __construct(
        public string $id = '',
        public string $title = '',
    ) {}
}
