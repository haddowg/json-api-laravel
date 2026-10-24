<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Attribute;

use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Attribute\VendorExtensions;
use haddowg\JsonApiLaravel\Operation\Operation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AsJsonApiSerializer::class)]
#[CoversClass(VendorExtensions::class)]
final class AsJsonApiSerializerTest extends TestCase
{
    public function test_extensions_default_to_none(): void
    {
        $attribute = new AsJsonApiSerializer(type: 'parts');

        self::assertSame([], $attribute->extensions);
        self::assertSame([], $attribute->operationExtensions);
    }

    public function test_it_carries_vendor_extensions(): void
    {
        $attribute = new AsJsonApiSerializer(
            type: 'parts',
            operations: [Operation::FetchCollection, Operation::FetchOne],
            extensions: ['x-feature' => 'parts', 'x-tags' => ['a', 'b']],
            operationExtensions: ['FetchOne' => ['x-feature' => 'parts-one']],
        );

        self::assertSame(['x-feature' => 'parts', 'x-tags' => ['a', 'b']], $attribute->extensions);
        self::assertSame(['FetchOne' => ['x-feature' => 'parts-one']], $attribute->operationExtensions);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidExtensions(): iterable
    {
        yield 'no x- prefix' => [['feature' => 'parts']];
        yield 'bare x-' => [['x-' => 'parts']];
        yield 'reserved x-oai-' => [['x-oai-thing' => true]];
        yield 'reserved x-oas- in any case' => [['X-OAS-thing' => true]];
        yield 'an object value' => [['x-thing' => new \stdClass()]];
        yield 'an object nested in an array' => [['x-thing' => ['ok', new \stdClass()]]];
    }

    /**
     * @param array<string, mixed> $extensions
     */
    #[DataProvider('invalidExtensions')]
    public function test_it_rejects_an_invalid_type_wide_extension(array $extensions): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AsJsonApiSerializer extensions');

        new AsJsonApiSerializer(type: 'parts', extensions: $extensions);
    }

    /**
     * @param array<string, mixed> $extensions
     */
    #[DataProvider('invalidExtensions')]
    public function test_it_rejects_an_invalid_per_operation_extension(array $extensions): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AsJsonApiSerializer operationExtensions[FetchOne]');

        new AsJsonApiSerializer(type: 'parts', operations: [Operation::FetchOne], operationExtensions: ['FetchOne' => $extensions]);
    }

    public function test_it_rejects_per_operation_extensions_for_an_unknown_operation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"Publish"');

        new AsJsonApiSerializer(type: 'parts', operations: [Operation::FetchOne], operationExtensions: ['Publish' => ['x-feature' => 'parts']]);
    }

    public function test_it_rejects_per_operation_extensions_for_an_unexposed_operation(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FetchCollection');

        new AsJsonApiSerializer(type: 'parts', operations: [Operation::FetchOne], operationExtensions: ['FetchCollection' => ['x-feature' => 'parts']]);
    }

    public function test_a_serialize_only_type_exposes_no_operation_to_extend(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FetchOne');

        new AsJsonApiSerializer(type: 'parts', operationExtensions: ['FetchOne' => ['x-feature' => 'parts']]);
    }
}
