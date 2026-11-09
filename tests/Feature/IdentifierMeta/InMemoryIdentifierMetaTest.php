<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\IdentifierMeta;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * @internal
 */
#[CoversNothing]
final class InMemoryIdentifierMetaTest extends IdentifierMetaTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
