<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The declaration checks `operationExamples` shares across the attributes that carry it
 * ({@see AsJsonApiResource}, {@see AsJsonApiSerializer}).
 *
 * @internal
 */
final class OperationExamples
{
    /**
     * Fails fast on an override core could not apply: an unknown operation name, a CRUD
     * operation the type does not expose, or a value other than an {@see OperationExample},
     * `false` or `null`. Core checks a relation name when it projects the document.
     *
     * @param string                  $attribute  the declaring attribute's short name, for the messages
     * @param array<array-key, mixed> $examples
     * @param list<string>            $exposed    the exposed operations as {@see Operation} case values
     * @param string                  $exposeHint how to expose an operation on this attribute
     *
     * @throws \LogicException
     */
    public static function assertValid(string $attribute, array $examples, array $exposed, string $exposeHint): void
    {
        foreach ($examples as $key => $example) {
            $key = (string) $key;
            $operation = OperationKeys::parse($attribute . ' operationExamples', $key)->kind->operationType();

            if ($operation !== null && !\in_array($operation->value, $exposed, true)) {
                throw new \LogicException(\sprintf(
                    '%s operationExamples names "%s", but the type does not expose it; %s.',
                    $attribute,
                    $key,
                    $exposeHint,
                ));
            }

            if ($example !== null && $example !== false && !$example instanceof OperationExample) {
                throw new \LogicException(\sprintf(
                    '%s operationExamples["%s"] must be an %s, false or null, got %s.',
                    $attribute,
                    $key,
                    OperationExample::class,
                    \get_debug_type($example),
                ));
            }
        }
    }
}
