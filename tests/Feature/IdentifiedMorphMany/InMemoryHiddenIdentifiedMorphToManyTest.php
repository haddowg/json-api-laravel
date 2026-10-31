<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\IdentifiedMorphMany;

/**
 * @internal
 */
final class InMemoryHiddenIdentifiedMorphToManyTest extends InMemoryIdentifiedMorphToManyTest
{
    protected function hides(): bool
    {
        return true;
    }
}
