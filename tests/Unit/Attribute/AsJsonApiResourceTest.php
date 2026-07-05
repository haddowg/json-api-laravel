<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Attribute;

use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApiLaravel\Tests\Fixtures\Overrides\MemoHydrator;
use haddowg\JsonApiLaravel\Tests\Fixtures\Overrides\NoteSerializer;
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

    public function test_it_carries_serializer_and_hydrator_overrides(): void
    {
        $attribute = new AsJsonApiResource(
            serializer: NoteSerializer::class,
            hydrator: MemoHydrator::class,
        );

        self::assertSame(NoteSerializer::class, $attribute->serializer);
        self::assertSame(MemoHydrator::class, $attribute->hydrator);
    }

    public function test_the_overrides_default_to_null(): void
    {
        $attribute = new AsJsonApiResource();

        self::assertNull($attribute->serializer);
        self::assertNull($attribute->hydrator);
    }

    public function test_read_only_and_operations_are_mutually_exclusive(): void
    {
        $this->expectException(\LogicException::class);

        new AsJsonApiResource(operations: [Operation::FetchOne], readOnly: true);
    }
}
