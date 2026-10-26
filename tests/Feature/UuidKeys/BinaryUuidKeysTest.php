<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\UuidKeys;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see UuidKeysTestCase} over binary UUID columns.
 *
 * @internal
 */
#[CoversNothing]
final class BinaryUuidKeysTest extends UuidKeysTestCase
{
    protected function storage(): string
    {
        return 'binary';
    }
}
