<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Attribute;

use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Operation\Operation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `deprecatedOperations` declaration checks on {@see AsJsonApiResource} and
 * {@see AsJsonApiSerializer}, and the `deprecated` flag of an {@see AsJsonApiAction}.
 *
 * @internal
 */
final class DeprecatedOperationsTest extends TestCase
{
    #[Test]
    public function aResourceKeepsItsList(): void
    {
        $attribute = new AsJsonApiResource(deprecatedOperations: ['delete', 'fetchRelated.tracks', 'removeRelationship.tags']);

        self::assertSame(['delete', 'fetchRelated.tracks', 'removeRelationship.tags'], $attribute->deprecatedOperations);
    }

    #[Test]
    public function aSerializerKeepsItsList(): void
    {
        $attribute = new AsJsonApiSerializer(type: 'charts', operations: [Operation::FetchOne], deprecatedOperations: ['fetchOne']);

        self::assertSame(['fetchOne'], $attribute->deprecatedOperations);
    }

    #[Test]
    public function anUnknownOperationNameIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource deprecatedOperations names "remove", which is not an operation');

        new AsJsonApiResource(deprecatedOperations: ['remove']);
    }

    #[Test]
    public function anOperationCaseValueIsNotAnOperationName(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource deprecatedOperations names "Delete", which is not an operation');

        new AsJsonApiResource(deprecatedOperations: ['Delete']);
    }

    #[Test]
    public function aRelationOperationWithoutARelationIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiSerializer deprecatedOperations names "fetchRelated", which is not an operation');

        new AsJsonApiSerializer(type: 'charts', deprecatedOperations: ['fetchRelated']);
    }

    #[Test]
    public function anUnexposedOperationOnAReadOnlyResourceIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource deprecatedOperations names "delete", but the type does not expose it; add it to `operations` (or drop `readOnly`).');

        new AsJsonApiResource(readOnly: true, deprecatedOperations: ['delete']);
    }

    #[Test]
    public function anUnexposedReadIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiSerializer deprecatedOperations names "fetchCollection", but the type does not expose it; add it to `operations`.');

        new AsJsonApiSerializer(type: 'charts', operations: [Operation::FetchOne], deprecatedOperations: ['fetchCollection']);
    }

    #[Test]
    public function aRelationshipMutationOnATypeWithoutUpdateIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource deprecatedOperations names "addRelationship.tags", a relationship mutation, which a type only exposes with Update; add it to `operations` (or drop `readOnly`).');

        new AsJsonApiResource(operations: [Operation::FetchOne, Operation::Create], deprecatedOperations: ['addRelationship.tags']);
    }

    #[Test]
    public function aRelatedReadOnAReadOnlyTypeIsAccepted(): void
    {
        $attribute = new AsJsonApiResource(readOnly: true, deprecatedOperations: ['fetchRelated.tags', 'fetchRelationship.tags']);

        self::assertSame(['fetchRelated.tags', 'fetchRelationship.tags'], $attribute->deprecatedOperations);
    }

    #[Test]
    public function aMapIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource deprecatedOperations must be a list of operation names.');

        // @phpstan-ignore argument.type
        new AsJsonApiResource(deprecatedOperations: ['delete' => true]);
    }

    #[Test]
    public function aNonStringEntryIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiSerializer deprecatedOperations must list operation names, got bool.');

        // @phpstan-ignore argument.type
        new AsJsonApiSerializer(type: 'charts', deprecatedOperations: [true]);
    }

    #[Test]
    public function anActionIsCurrentUnlessDeclaredDeprecated(): void
    {
        self::assertFalse((new AsJsonApiAction(type: 'albums', path: 'go'))->deprecated);
        self::assertTrue((new AsJsonApiAction(type: 'albums', path: 'go', deprecated: true))->deprecated);
    }
}
