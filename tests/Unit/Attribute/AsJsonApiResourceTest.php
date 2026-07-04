<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Attribute;

use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AsJsonApiResource::class)]
final class AsJsonApiResourceTest extends TestCase
{
    public function test_it_accepts_the_read_only_shorthand(): void
    {
        $attribute = new AsJsonApiResource(readOnly: true);

        self::assertTrue($attribute->readOnly);
        self::assertSame([], $attribute->operations);
    }

    public function test_it_accepts_an_explicit_operation_list(): void
    {
        $attribute = new AsJsonApiResource(operations: [Operation::FetchCollection, Operation::Create]);

        self::assertSame([Operation::FetchCollection, Operation::Create], $attribute->operations);
    }

    public function test_read_only_and_operations_are_mutually_exclusive(): void
    {
        $this->expectException(\LogicException::class);

        new AsJsonApiResource(operations: [Operation::FetchOne], readOnly: true);
    }
}
