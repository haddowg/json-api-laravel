<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

/**
 * The request document of the `import` action: the secrets to import.
 */
final class FkImport
{
    public ?string $id = null;

    /**
     * @var iterable<mixed>
     */
    public iterable $secrets = [];
}
