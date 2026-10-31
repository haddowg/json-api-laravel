<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelationshipEntityConstraints;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApiLaravel\Validation\ConstraintTranslatorInterface;
use Illuminate\Contracts\Validation\ValidationRule;

final class EntityCheckTranslator implements ConstraintTranslatorInterface
{
    public function supports(ConstraintInterface $constraint): bool
    {
        return $constraint instanceof EntityCheck;
    }

    public function translate(ConstraintInterface $constraint): array
    {
        \assert($constraint instanceof EntityCheck);
        $check = $constraint->check;

        return [new class ($check) implements ValidationRule {
            /**
             * @param \Closure(object): ?string $check
             */
            public function __construct(private readonly \Closure $check) {}

            public function validate(string $attribute, mixed $value, \Closure $fail): void
            {
                $message = \is_object($value) ? ($this->check)($value) : null;
                if ($message !== null) {
                    $fail($message);
                }
            }
        }];
    }
}
