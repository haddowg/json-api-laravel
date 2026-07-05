<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * A device — a plain mutable domain object with an app-generated ULID id.
 */
final class Device
{
    public function __construct(
        public string $id = '',
        public string $label = '',
    ) {}
}
