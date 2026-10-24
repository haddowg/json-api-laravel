<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\UriFieldName;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * @internal
 */
#[CoversNothing]
final class EloquentRelationUriFieldNameTest extends RelationUriFieldNameTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }
}
