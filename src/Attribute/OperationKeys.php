<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;

/**
 * Parses the operation names `operationExamples` and `operationScopes` are keyed by, through
 * core's {@see OperationKey}, so both maps share one grammar.
 *
 * @internal
 */
final class OperationKeys
{
    /**
     * The key `$name` parses to.
     *
     * @param string $attribute the declaring attribute and option, for the message
     *
     * @throws \LogicException when `$name` is not an operation name
     */
    public static function parse(string $attribute, string $name): OperationKey
    {
        return OperationKey::tryFromName($name) ?? throw new \LogicException(\sprintf(
            '%s names "%s", which is not an operation; use one of %s, or %s followed by a dot and the relation name.',
            $attribute,
            $name,
            \implode(', ', self::kinds(static fn(OperationKind $kind): bool => $kind->operationType() !== null)),
            \implode(', ', self::kinds(static fn(OperationKind $kind): bool => $kind->takesRelation())),
        ));
    }

    /**
     * @param \Closure(OperationKind): bool $filter
     *
     * @return list<string>
     */
    private static function kinds(\Closure $filter): array
    {
        return \array_values(\array_map(
            static fn(OperationKind $kind): string => $kind->value,
            \array_filter(OperationKind::cases(), $filter),
        ));
    }
}
