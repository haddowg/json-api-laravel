<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\DateMessage;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see DateMessageTestCase} over Eloquent.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentDateMessageTest extends DateMessageTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }
}
