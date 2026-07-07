<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\PaginatorKind;
use haddowg\JsonApiLaravel\OpenApi\Metadata\TypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the read side of the per-operation response plumbing: {@see TypeMetadata::responsesFor()}
 * rehydrates a declared override from its scalar snapshot form into
 * {@see OperationResponseInterface} carriers (status + job type), and falls back to core's
 * per-operation default when a type declares no override.
 *
 * @internal
 */
#[CoversClass(TypeMetadata::class)]
final class TypeMetadataResponsesTest extends TestCase
{
    public function test_it_rehydrates_declared_responses_for_an_operation(): void
    {
        $metadata = $this->metadata([
            OperationType::Create->value => [
                ['status' => 201, 'jobType' => null],
                ['status' => 202, 'jobType' => 'jobs'],
            ],
        ]);

        $responses = $metadata->responsesFor(OperationType::Create);

        self::assertSame(
            [201, 202],
            \array_map(static fn(OperationResponseInterface $response): int => $response->status(), $responses),
        );
        self::assertSame(
            [null, 'jobs'],
            \array_map(static fn(OperationResponseInterface $response): ?string => $response->jobType(), $responses),
        );
    }

    public function test_it_falls_back_to_the_operation_default_when_undeclared(): void
    {
        $responses = $this->metadata([])->responsesFor(OperationType::FetchOne);

        self::assertCount(1, $responses);

        $first = $responses[0] ?? null;
        self::assertInstanceOf(OperationResponseInterface::class, $first);
        self::assertSame(200, $first->status());
    }

    /**
     * @param array<string, list<array{status: int, jobType: string|null}>> $responses
     */
    private function metadata(array $responses): TypeMetadata
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
            paginatorKind: PaginatorKind::None,
            countable: false,
            filters: [],
            sorts: [],
            actions: [],
            tags: [],
            description: null,
            operationDescriptions: [],
            includablePaths: [],
            responses: $responses,
        );
    }
}
