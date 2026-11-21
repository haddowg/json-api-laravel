<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedConversions;

/**
 * @internal
 */
enum Mood: string
{
    case Calm = 'calm';
    case Busy = 'busy';
}
