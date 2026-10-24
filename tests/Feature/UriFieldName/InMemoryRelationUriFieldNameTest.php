<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\UriFieldName;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * @internal
 */
#[CoversNothing]
final class InMemoryRelationUriFieldNameTest extends RelationUriFieldNameTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
