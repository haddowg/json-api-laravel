<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Operation\Operation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `operationExamples` declaration checks on {@see AsJsonApiResource} and
 * {@see AsJsonApiSerializer}.
 *
 * @internal
 */
final class OperationExamplesTest extends TestCase
{
    #[Test]
    public function aResourceKeepsItsCrudAndRelationOverrides(): void
    {
        $update = new OperationExample(responses: [200 => ['meta' => ['saved' => true]]]);

        $attribute = new AsJsonApiResource(operationExamples: [
            'update' => $update,
            'fetchOne' => false,
            'fetchRelated.artist' => null,
        ]);

        self::assertSame(['update' => $update, 'fetchOne' => false, 'fetchRelated.artist' => null], $attribute->operationExamples);
    }

    #[Test]
    public function anUnknownOperationNameIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource operationExamples names "FetchOne", which is not an operation');

        new AsJsonApiResource(operationExamples: ['FetchOne' => false]);
    }

    #[Test]
    public function aRelationKeyNeedsARelationName(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"fetchRelated."');

        new AsJsonApiResource(operationExamples: ['fetchRelated.' => false]);
    }

    #[Test]
    public function anUnexposedOperationIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource operationExamples names "update", but the type does not expose it');

        new AsJsonApiResource(readOnly: true, operationExamples: ['update' => false]);
    }

    #[Test]
    public function aValueOfAnotherKindIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource operationExamples["create"] must be an');

        // @phpstan-ignore argument.type
        new AsJsonApiResource(operationExamples: ['create' => ['data' => []]]);
    }

    #[Test]
    public function aSerializerChecksAgainstItsOwnOperations(): void
    {
        $attribute = new AsJsonApiSerializer(type: 'parts', operations: [Operation::FetchOne], operationExamples: ['fetchOne' => false]);
        self::assertSame(['fetchOne' => false], $attribute->operationExamples);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiSerializer operationExamples names "fetchCollection", but the type does not expose it');

        new AsJsonApiSerializer(type: 'parts', operations: [Operation::FetchOne], operationExamples: ['fetchCollection' => false]);
    }
}
