<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The declaration checks `operationScopes` shares across the attributes that carry it
 * ({@see AsJsonApiResource}, {@see AsJsonApiSerializer}), and the `scopes` list of an
 * {@see AsJsonApiAction}.
 *
 * @internal
 */
final class OperationScopes
{
    /**
     * Fails fast on an override the scope table build would refuse later: an unknown
     * operation name, a write the type does not expose, or a value other than a list of
     * strings. `fetchOne` and `fetchCollection` are always accepted, because every type has
     * them in the table for the include hops that reach it. Core checks a relation name and
     * each scope's characters when it builds the table.
     *
     * @param string                  $attribute  the declaring attribute's short name, for the messages
     * @param array<array-key, mixed> $scopes
     * @param list<string>            $exposed    the exposed operations as {@see Operation} case values
     * @param string                  $exposeHint how to expose an operation on this attribute
     *
     * @throws \LogicException
     */
    public static function assertValid(string $attribute, array $scopes, array $exposed, string $exposeHint): void
    {
        foreach ($scopes as $key => $list) {
            $key = (string) $key;
            $operation = OperationKeys::parse($attribute . ' operationScopes', $key)->kind->operationType();

            if ($operation !== null
                && $operation !== OperationType::FetchOne
                && $operation !== OperationType::FetchCollection
                && !\in_array($operation->value, $exposed, true)) {
                throw new \LogicException(\sprintf(
                    '%s operationScopes names "%s", but the type does not expose it; %s.',
                    $attribute,
                    $key,
                    $exposeHint,
                ));
            }

            self::assertList(\sprintf('%s operationScopes["%s"]', $attribute, $key), $list);
        }
    }

    /**
     * Fails unless `$scopes` is a list of non-empty strings.
     *
     * @throws \LogicException
     */
    public static function assertList(string $label, mixed $scopes): void
    {
        if (!\is_array($scopes) || !\array_is_list($scopes)) {
            throw new \LogicException(\sprintf('%s must be a list of scopes, got %s.', $label, \get_debug_type($scopes)));
        }

        foreach ($scopes as $scope) {
            if (!\is_string($scope) || $scope === '') {
                throw new \LogicException(\sprintf('%s must list non-empty strings, got %s.', $label, \get_debug_type($scope)));
            }
        }
    }
}
