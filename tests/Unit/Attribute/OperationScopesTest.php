<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Attribute;

use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Operation\Operation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `operationScopes` declaration checks on {@see AsJsonApiResource} and
 * {@see AsJsonApiSerializer}, and the `scopes` list of an {@see AsJsonApiAction}.
 *
 * @internal
 */
final class OperationScopesTest extends TestCase
{
    #[Test]
    public function aResourceKeepsItsOverrides(): void
    {
        $attribute = new AsJsonApiResource(operationScopes: [
            'delete' => ['library:write', 'library:admin'],
            'fetchRelated.tracks' => [],
        ]);

        self::assertSame(['delete' => ['library:write', 'library:admin'], 'fetchRelated.tracks' => []], $attribute->operationScopes);
    }

    #[Test]
    public function theReadsMayBeNamedOnATypeThatDoesNotExposeThem(): void
    {
        $attribute = new AsJsonApiSerializer(type: 'charts', operationScopes: ['fetchOne' => ['charts:read'], 'fetchCollection' => ['charts:read']]);

        self::assertSame(['charts:read'], $attribute->operationScopes['fetchOne']);
    }

    #[Test]
    public function anUnknownOperationNameIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource operationScopes names "remove", which is not an operation');

        new AsJsonApiResource(operationScopes: ['remove' => ['x']]);
    }

    #[Test]
    public function anUnexposedWriteIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiSerializer operationScopes names "create", but the type does not expose it');

        new AsJsonApiSerializer(type: 'charts', operations: [Operation::FetchOne], operationScopes: ['create' => ['x']]);
    }

    #[Test]
    public function aValueMustBeAListOfScopes(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource operationScopes["update"] must be a list of scopes, got string.');

        // @phpstan-ignore argument.type
        new AsJsonApiResource(operationScopes: ['update' => 'albums:write']);
    }

    #[Test]
    public function anActionsScopesMustBeAListOfNonEmptyStrings(): void
    {
        self::assertSame(['a', 'b'], (new AsJsonApiAction(type: 'albums', path: 'go', scope: ActionScope::Resource, scopes: ['a', 'b']))->scopes);
        self::assertNull((new AsJsonApiAction(type: 'albums', path: 'go'))->scopes);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiAction "go" on type "albums" scopes must list non-empty strings');

        new AsJsonApiAction(type: 'albums', path: 'go', scopes: ['']);
    }
}
