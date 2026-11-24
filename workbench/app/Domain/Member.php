<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A member, the related side of the required-relation tasks on the in-memory witness.
 */
final class Member
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
