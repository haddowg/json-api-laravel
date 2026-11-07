<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Attribute;

use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The declaration checks `operationErrors` shares across the attributes that carry it
 * ({@see AsJsonApiResource}, {@see AsJsonApiSerializer}), and the `errors` list of an
 * {@see AsJsonApiAction}.
 *
 * @internal
 */
final class OperationErrors
{
    /**
     * Fails fast on a map core's projection would refuse later: a key that is neither `*`,
     * an operation name nor an action's `action.<path>.<method>` name, a CRUD operation the
     * type does not expose, a relationship mutation on a type without {@see Operation::Update},
     * or a value other than a non-empty list of described error classes. Core checks a
     * relation or action name against the type when it projects the document.
     *
     * @param string                  $attribute  the declaring attribute's short name, for the messages
     * @param array<array-key, mixed> $errors
     * @param list<string>            $exposed    the exposed operations as {@see Operation} case values
     * @param string                  $exposeHint how to expose an operation on this attribute
     *
     * @throws \LogicException
     */
    public static function assertValid(string $attribute, array $errors, array $exposed, string $exposeHint): void
    {
        foreach ($errors as $key => $classes) {
            $key = (string) $key;
            self::assertKey($attribute, $key, $exposed, $exposeHint);

            $label = \sprintf('%s operationErrors["%s"]', $attribute, $key);
            self::assertList($label, $classes);
            if ($classes === []) {
                throw new \LogicException(\sprintf('%s must name at least one described error class; drop the key to declare none.', $label));
            }
        }
    }

    /**
     * Fails unless `$classes` is a list of classes implementing {@see DescribedErrorInterface}.
     *
     * @throws \LogicException
     */
    public static function assertList(string $label, mixed $classes): void
    {
        if (!\is_array($classes) || !\array_is_list($classes)) {
            throw new \LogicException(\sprintf('%s must be a list of described error classes, got %s.', $label, \get_debug_type($classes)));
        }

        foreach ($classes as $class) {
            if (!\is_string($class) || !\is_a($class, DescribedErrorInterface::class, true)) {
                throw new \LogicException(\sprintf(
                    '%s must list classes implementing %s, got %s.',
                    $label,
                    DescribedErrorInterface::class,
                    \is_string($class) ? '"' . $class . '"' : \get_debug_type($class),
                ));
            }
        }
    }

    /**
     * @param list<string> $exposed
     *
     * @throws \LogicException
     */
    private static function assertKey(string $attribute, string $key, array $exposed, string $exposeHint): void
    {
        if ($key === '*') {
            return;
        }

        // An action's name does not parse back to a key (it cannot carry the action's scope),
        // so only its shape is checked here and core matches it against the type's actions.
        if (\str_starts_with($key, OperationKind::Action->value . '.')) {
            if (\preg_match('/^action\.[A-Za-z0-9._-]+\.(get|head|post|put|patch|delete|options)$/', $key) !== 1) {
                throw new \LogicException(\sprintf(
                    '%s operationErrors names "%s", which is not an action operation; name one as action.<path>.<method> with the method in lower case.',
                    $attribute,
                    $key,
                ));
            }

            return;
        }

        $kind = OperationKeys::parse($attribute . ' operationErrors', $key)->kind;
        $operation = $kind->operationType();
        if ($operation !== null && !\in_array($operation->value, $exposed, true)) {
            throw new \LogicException(\sprintf(
                '%s operationErrors names "%s", but the type does not expose it; %s.',
                $attribute,
                $key,
                $exposeHint,
            ));
        }

        if (\in_array($kind, [OperationKind::UpdateRelationship, OperationKind::AddRelationship, OperationKind::RemoveRelationship], true)
            && !\in_array(Operation::Update->value, $exposed, true)) {
            throw new \LogicException(\sprintf(
                '%s operationErrors names "%s", a relationship mutation, which a type only exposes with %s; %s.',
                $attribute,
                $key,
                Operation::Update->value,
                $exposeHint,
            ));
        }
    }
}
