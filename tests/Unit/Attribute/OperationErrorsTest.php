<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Attribute;

use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApiLaravel\Tests\Fixtures\OperationErrors\DepositRequired;
use haddowg\JsonApiLaravel\Tests\Fixtures\OperationErrors\LockerLocked;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `operationErrors` declaration checks on {@see AsJsonApiResource} and
 * {@see AsJsonApiSerializer}, and the `errors` list of an {@see AsJsonApiAction}.
 *
 * @internal
 */
final class OperationErrorsTest extends TestCase
{
    #[Test]
    public function aResourceKeepsItsDeclaration(): void
    {
        $declared = [
            '*' => [DepositRequired::class],
            'update' => [LockerLocked::class],
            'fetchRelated.tracks' => [LockerLocked::class],
            'action.publish.post' => [DepositRequired::class],
        ];

        self::assertSame($declared, (new AsJsonApiResource(operationErrors: $declared))->operationErrors);
    }

    #[Test]
    public function aSerializerKeepsItsDeclaration(): void
    {
        $attribute = new AsJsonApiSerializer(type: 'charts', operations: [Operation::FetchOne], operationErrors: ['fetchOne' => [LockerLocked::class]]);

        self::assertSame(['fetchOne' => [LockerLocked::class]], $attribute->operationErrors);
    }

    #[Test]
    public function anUnknownOperationNameIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource operationErrors names "remove", which is not an operation');

        new AsJsonApiResource(operationErrors: ['remove' => [LockerLocked::class]]);
    }

    #[Test]
    public function aMalformedActionNameIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource operationErrors names "action.publish.POST", which is not an action operation');

        new AsJsonApiResource(operationErrors: ['action.publish.POST' => [LockerLocked::class]]);
    }

    #[Test]
    public function anUnexposedOperationIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiSerializer operationErrors names "fetchCollection", but the type does not expose it');

        new AsJsonApiSerializer(type: 'charts', operations: [Operation::FetchOne], operationErrors: ['fetchCollection' => [LockerLocked::class]]);
    }

    #[Test]
    public function aRelationshipMutationNeedsUpdate(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource operationErrors names "addRelationship.tracks", a relationship mutation, which a type only exposes with Update');

        new AsJsonApiResource(readOnly: true, operationErrors: ['addRelationship.tracks' => [LockerLocked::class]]);
    }

    #[Test]
    public function aValueMustBeAList(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource operationErrors["update"] must be a list of described error classes, got string.');

        // @phpstan-ignore argument.type
        new AsJsonApiResource(operationErrors: ['update' => LockerLocked::class]);
    }

    #[Test]
    public function aValueMustNameAtLeastOneClass(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource operationErrors["update"] must name at least one described error class');

        new AsJsonApiResource(operationErrors: ['update' => []]);
    }

    #[Test]
    public function aValueMustListDescribedErrors(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource operationErrors["update"] must list classes implementing haddowg\JsonApi\Exception\DescribedErrorInterface, got "RuntimeException".');

        // @phpstan-ignore argument.type
        new AsJsonApiResource(operationErrors: ['update' => [\RuntimeException::class]]);
    }

    #[Test]
    public function anActionsErrorsMustListDescribedErrors(): void
    {
        self::assertSame([DepositRequired::class], (new AsJsonApiAction(type: 'lockers', path: 'open', errors: [DepositRequired::class]))->errors);
        self::assertSame([], (new AsJsonApiAction(type: 'lockers', path: 'open'))->errors);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiAction "open" on type "lockers" errors must list classes implementing');

        // @phpstan-ignore argument.type
        new AsJsonApiAction(type: 'lockers', path: 'open', errors: ['NotAClass']);
    }
}
