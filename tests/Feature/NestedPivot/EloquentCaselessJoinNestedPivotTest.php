<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\NestedPivot;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * @internal
 */
#[CoversNothing]
final class EloquentCaselessJoinNestedPivotTest extends EloquentKeyedNestedPivotTestCase
{
    protected function keys(): string
    {
        return 'nocase';
    }
}
