<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\FirstPage;

final class InMemoryFirstPageTest extends FirstPageTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
