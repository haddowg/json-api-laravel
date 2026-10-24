<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\Created;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
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

    public function test_it_normalises_a_single_response_override_to_a_list(): void
    {
        $attribute = new AsJsonApiResource(create: new Accepted('jobs'));

        $responses = $attribute->create;
        self::assertCount(1, $responses);

        $first = $responses[0] ?? null;
        self::assertInstanceOf(OperationResponseInterface::class, $first);
        self::assertSame(202, $first->status());
        self::assertSame('jobs', $first->jobType());
    }

    public function test_it_accepts_a_list_of_response_overrides(): void
    {
        $attribute = new AsJsonApiResource(create: [new Created(), new Accepted('jobs')]);

        self::assertSame(
            [201, 202],
            \array_map(static fn(OperationResponseInterface $response): int => $response->status(), $attribute->create),
        );
    }

    public function test_it_rejects_a_duplicate_status_code_in_an_override(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AsJsonApiResource(create: [new Created(), new Created()]);
    }

    public function test_it_rejects_a_response_override_for_a_read_only_suppressed_operation(): void
    {
        $this->expectException(\LogicException::class);

        new AsJsonApiResource(readOnly: true, create: new Created());
    }

    public function test_it_rejects_a_response_override_absent_from_the_operations_allow_list(): void
    {
        $this->expectException(\LogicException::class);

        new AsJsonApiResource(operations: [Operation::FetchOne], update: new NoContent());
    }

    public function test_it_carries_vendor_extensions(): void
    {
        $attribute = new AsJsonApiResource(
            extensions: ['x-feature' => 'agency', 'x-tags' => ['a', 'b']],
            operationExtensions: ['Create' => ['x-feature' => 'agency-write']],
        );

        self::assertSame(['x-feature' => 'agency', 'x-tags' => ['a', 'b']], $attribute->extensions);
        self::assertSame(['Create' => ['x-feature' => 'agency-write']], $attribute->operationExtensions);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidExtensions(): iterable
    {
        yield 'no x- prefix' => [['feature' => 'agency']];
        yield 'bare x-' => [['x-' => 'agency']];
        yield 'reserved x-oai-' => [['x-oai-thing' => true]];
        yield 'reserved x-oas- in any case' => [['X-OAS-thing' => true]];
        yield 'an object value' => [['x-thing' => new \stdClass()]];
        yield 'an object nested in an array' => [['x-thing' => ['ok', new \stdClass()]]];
    }

    /**
     * @param array<string, mixed> $extensions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidExtensions')]
    public function test_it_rejects_an_invalid_type_wide_extension(array $extensions): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AsJsonApiResource(extensions: $extensions);
    }

    /**
     * @param array<string, mixed> $extensions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidExtensions')]
    public function test_it_rejects_an_invalid_per_operation_extension(array $extensions): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AsJsonApiResource(operationExtensions: ['Update' => $extensions]);
    }

    public function test_it_rejects_per_operation_extensions_for_an_unknown_operation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"Publish"');

        new AsJsonApiResource(operationExtensions: ['Publish' => ['x-feature' => 'agency']]);
    }

    public function test_it_rejects_per_operation_extensions_for_an_unexposed_operation(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Create');

        new AsJsonApiResource(readOnly: true, operationExtensions: ['Create' => ['x-feature' => 'agency']]);
    }
}
