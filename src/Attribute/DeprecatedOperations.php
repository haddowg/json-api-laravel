<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The declaration checks `deprecatedOperations` shares across the attributes that carry it
 * ({@see AsJsonApiResource}, {@see AsJsonApiSerializer}).
 *
 * @internal
 */
final class DeprecatedOperations
{
    /**
     * Fails fast on a list core could not apply: something other than a list of strings, an
     * unknown operation name, a CRUD operation the type does not expose, or a relationship
     * mutation on a type without {@see Operation::Update}, which is the only way those
     * endpoints are routed. Core checks a relation name when it projects the document.
     *
     * @param string       $attribute  the declaring attribute's short name, for the messages
     * @param array<mixed> $operations
     * @param list<string> $exposed    the exposed operations as {@see Operation} case values
     * @param string       $exposeHint how to expose an operation on this attribute
     *
     * @throws \LogicException
     */
    public static function assertValid(string $attribute, array $operations, array $exposed, string $exposeHint): void
    {
        if (!\array_is_list($operations)) {
            throw new \LogicException(\sprintf('%s deprecatedOperations must be a list of operation names.', $attribute));
        }

        foreach ($operations as $name) {
            if (!\is_string($name)) {
                throw new \LogicException(\sprintf(
                    '%s deprecatedOperations must list operation names, got %s.',
                    $attribute,
                    \get_debug_type($name),
                ));
            }

            $kind = OperationKeys::parse($attribute . ' deprecatedOperations', $name)->kind;
            $operation = $kind->operationType();
            if ($operation !== null && !\in_array($operation->value, $exposed, true)) {
                throw new \LogicException(\sprintf(
                    '%s deprecatedOperations names "%s", but the type does not expose it; %s.',
                    $attribute,
                    $name,
                    $exposeHint,
                ));
            }

            if (\in_array($kind, [OperationKind::UpdateRelationship, OperationKind::AddRelationship, OperationKind::RemoveRelationship], true)
                && !\in_array(Operation::Update->value, $exposed, true)) {
                throw new \LogicException(\sprintf(
                    '%s deprecatedOperations names "%s", a relationship mutation, which a type only exposes with %s; %s.',
                    $attribute,
                    $name,
                    Operation::Update->value,
                    $exposeHint,
                ));
            }
        }
    }
}
