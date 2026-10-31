<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\IdentifiedMorphMany;

/**
 * @internal
 */
final class EloquentHiddenIdentifiedMorphToManyTest extends EloquentIdentifiedMorphToManyTest
{
    protected function hides(): bool
    {
        return true;
    }
}
