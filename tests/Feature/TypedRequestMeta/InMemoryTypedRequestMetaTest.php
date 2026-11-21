<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\TypedRequestMeta;

final class InMemoryTypedRequestMetaTest extends TypedRequestMetaTestCase
{
    protected function store(): string
    {
        return 'memory';
    }
}
