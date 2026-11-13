<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\RelatedQuery;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class EloquentRelatedQueryOperationsTest extends RelatedQueryOperationsTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }
}
