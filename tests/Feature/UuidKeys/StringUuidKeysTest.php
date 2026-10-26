<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\UuidKeys;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see UuidKeysTestCase} over string UUID columns.
 *
 * @internal
 */
#[CoversNothing]
final class StringUuidKeysTest extends UuidKeysTestCase
{
    protected function storage(): string
    {
        return 'string';
    }
}
