<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The declaration checks `extensions`/`operationExtensions` share across the attributes
 * that carry them ({@see AsJsonApiResource}, {@see AsJsonApiSerializer}, {@see AsJsonApiAction}).
 *
 * @internal
 */
final class VendorExtensions
{
    /**
     * Fails fast on a vendor extension the OpenAPI document can't carry: a key without the
     * `x-` prefix, a reserved `x-oai-`/`x-oas-` key, a value the discovery cache can't
     * export, or a per-operation set for an unknown, unexposed or twice-named operation.
     *
     * An `operationExtensions` key is either an {@see Operation} case value or a core
     * operation name ({@see OperationKey}). `Create` and `create` name the same operation, so
     * declaring both is an error. Core checks a relation name when it projects the document.
     *
     * @param string                  $attribute           the declaring attribute's short name, for the messages
     * @param array<array-key, mixed> $extensions
     * @param array<array-key, mixed> $operationExtensions
     * @param list<string>            $exposed             the exposed operations as {@see Operation} case values
     * @param string                  $exposeHint          how to expose an operation on this attribute
     */
    public static function assertValid(
        string $attribute,
        array $extensions,
        array $operationExtensions,
        array $exposed,
        string $exposeHint,
    ): void {
        self::assertMap($attribute, $extensions, 'extensions');

        $seen = [];
        foreach ($operationExtensions as $operation => $set) {
            $operation = (string) $operation;
            $key = self::operationKey($attribute, $operation);
            $crud = $key->kind->operationType();
            if ($crud !== null && !\in_array($crud->value, $exposed, true)) {
                throw new \LogicException(\sprintf(
                    '%s declares operationExtensions for the %s operation, but it is not exposed; %s.',
                    $attribute,
                    $operation,
                    $exposeHint,
                ));
            }
            $name = $key->name();
            if (isset($seen[$name])) {
                throw new \InvalidArgumentException(\sprintf(
                    '%s declares operationExtensions for "%s" and "%s", which name the same operation; merge them under one key.',
                    $attribute,
                    $seen[$name],
                    $operation,
                ));
            }
            $seen[$name] = $operation;
            if (!\is_array($set)) {
                throw new \InvalidArgumentException(\sprintf(
                    '%s operationExtensions for %s must be a map of "x-" keys to values.',
                    $attribute,
                    $operation,
                ));
            }
            self::assertMap($attribute, $set, \sprintf('operationExtensions[%s]', $operation));
        }
    }

    private static function operationKey(string $attribute, string $operation): OperationKey
    {
        $case = Operation::tryFrom($operation);
        if ($case !== null) {
            return OperationKey::crud(OperationType::from($case->value));
        }

        return OperationKey::tryFromName($operation) ?? throw new \InvalidArgumentException(\sprintf(
            '%s declares operationExtensions for "%s", which is not an operation; use a %s case value or an operation name such as fetchOne or fetchRelated.<relation>.',
            $attribute,
            $operation,
            Operation::class,
        ));
    }

    /**
     * Fails fast on one map of vendor extensions: a key without the `x-` prefix, a reserved
     * `x-oai-`/`x-oas-` key, or a value the discovery cache can't export.
     *
     * @param array<array-key, mixed> $extensions
     */
    public static function assertMap(string $attribute, array $extensions, string $where): void
    {
        foreach ($extensions as $key => $value) {
            $key = (string) $key;
            if (!\str_starts_with($key, 'x-') || $key === 'x-') {
                throw new \InvalidArgumentException(\sprintf(
                    '%s %s key "%s" is invalid: an OpenAPI vendor extension key is "x-" followed by a name.',
                    $attribute,
                    $where,
                    $key,
                ));
            }
            $lower = \strtolower($key);
            if (\str_starts_with($lower, 'x-oai-') || \str_starts_with($lower, 'x-oas-')) {
                throw new \InvalidArgumentException(\sprintf(
                    '%s %s key "%s" is invalid: the "x-oai-" and "x-oas-" prefixes are reserved by the OpenAPI Initiative.',
                    $attribute,
                    $where,
                    $key,
                ));
            }
            if (!self::isExportable($value)) {
                throw new \InvalidArgumentException(\sprintf(
                    '%s %s value for "%s" must be a scalar, null, or an array of them.',
                    $attribute,
                    $where,
                    $key,
                ));
            }
        }
    }

    private static function isExportable(mixed $value): bool
    {
        if (\is_array($value)) {
            foreach ($value as $item) {
                if (!self::isExportable($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || \is_scalar($value);
    }
}
