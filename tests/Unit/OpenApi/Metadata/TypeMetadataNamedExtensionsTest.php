<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApiLaravel\OpenApi\Metadata\TypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The attribute's one `operationExtensions` map splits by key form: case values answer the
 * CRUD seam and core operation names answer the named seam, so neither sees the other's sets.
 *
 * @internal
 */
#[CoversClass(TypeMetadata::class)]
final class TypeMetadataNamedExtensionsTest extends TestCase
{
    public function test_case_values_answer_the_crud_seam_and_names_the_named_one(): void
    {
        $metadata = $this->metadata([
            'Create' => ['x-stability' => 'stable'],
            'fetchOne' => ['x-cache' => ['ttl' => 60]],
            'fetchRelated.agencies' => ['x-mcp' => false],
        ]);

        self::assertSame(['x-stability' => 'stable'], $metadata->operationExtensions(OperationType::Create));
        self::assertSame([], $metadata->operationExtensions(OperationType::FetchOne));
        self::assertSame([
            'fetchOne' => ['x-cache' => ['ttl' => 60]],
            'fetchRelated.agencies' => ['x-mcp' => false],
        ], $metadata->namedOperationExtensions());
    }

    public function test_a_type_without_per_operation_sets_names_none(): void
    {
        self::assertSame([], $this->metadata([])->namedOperationExtensions());
    }

    /**
     * @param array<string, array<string, mixed>> $operationExtensions
     */
    private function metadata(array $operationExtensions): TypeMetadata
    {
        return new TypeMetadata(
            type: 'widgets',
            uriType: 'widgets',
            hasFields: false,
            fields: [],
            relations: [],
            operations: [OperationType::Create, OperationType::FetchOne],
            securedOperations: [],
            publicOperations: [],
            allowsClientId: false,
            requiresClientId: false,
            idPattern: null,
            pageSchema: null,
            countable: false,
            filters: [],
            sorts: [],
            actions: [],
            tags: [],
            description: null,
            operationDescriptions: [],
            includablePaths: [],
            operationExtensions: $operationExtensions,
        );
    }
}
