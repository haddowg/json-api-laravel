<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

/**
 * The positioned in-memory wiring with song 2 hidden by a songs provider of its own.
 */
final class PositionedHiddenInMemoryServiceProvider extends PositionedInMemoryServiceProvider
{
    protected function hiddenSongs(): array
    {
        return ['2'];
    }
}
