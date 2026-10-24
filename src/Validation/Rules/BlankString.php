<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\EmailFormat;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMin;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Constraint\IpFormat;
use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\MaxLength;
use haddowg\JsonApi\Resource\Constraint\MaxProperties;
use haddowg\JsonApi\Resource\Constraint\Min;
use haddowg\JsonApi\Resource\Constraint\MinItems;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Constraint\MinProperties;
use haddowg\JsonApi\Resource\Constraint\MultipleOf;
use haddowg\JsonApi\Resource\Constraint\NotIn;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\SlugFormat;
use haddowg\JsonApi\Resource\Constraint\UlidFormat;
use haddowg\JsonApi\Resource\Constraint\UrlFormat;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an empty or whitespace-only string against one core constraint. Laravel skips
 * every non-implicit rule for such a string, so the rules the
 * {@see \haddowg\JsonApiLaravel\Validation\ConstraintTranslator} emits never see it. This
 * implicit companion checks only those strings and gives them the outcome the Symfony
 * bundle gives them:
 *  - a length bound measures the string, so `"   "` passes `minLength(1)`;
 *  - a pattern or format check passes `""` and checks a whitespace-only string;
 *  - `In`/`NotIn` test membership;
 *  - a lower numeric bound or `multipleOf` rejects it as not numeric, and an item or
 *    property count rejects it as not an array.
 *
 * A composite rule (`When`, `AtLeastOneOf`) or a date bound is passed in as `$delegate`
 * and runs on the blank string as it would on any other string; its inner rules carry
 * their own companions.
 */
final class BlankString implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(
        private readonly ConstraintInterface $constraint,
        private readonly ?ValidationRule $delegate = null,
    ) {}

    /**
     * Whether a blank string can fail `$constraint` in the bundle when Laravel would skip it.
     */
    public static function applies(ConstraintInterface $constraint): bool
    {
        return match (true) {
            $constraint instanceof MinLength => $constraint->value > 0,
            $constraint instanceof MaxLength,
            $constraint instanceof Pattern,
            $constraint instanceof SlugFormat,
            $constraint instanceof EmailFormat,
            $constraint instanceof UrlFormat,
            $constraint instanceof UuidFormat,
            $constraint instanceof UlidFormat,
            $constraint instanceof IpFormat,
            $constraint instanceof In,
            $constraint instanceof NotIn,
            $constraint instanceof Min,
            $constraint instanceof ExclusiveMin,
            $constraint instanceof MultipleOf,
            $constraint instanceof MinItems,
            $constraint instanceof MaxItems,
            $constraint instanceof MinProperties,
            $constraint instanceof MaxProperties => true,
            default => false,
        };
    }

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!\is_string($value) || \trim($value) !== '') {
            return;
        }

        if ($this->delegate !== null) {
            $this->delegate->validate($attribute, $value, $fail);

            return;
        }

        $constraint = $this->constraint;
        match (true) {
            $constraint instanceof MinLength => \mb_strlen($value) < $constraint->value
                ? $fail('validation.min.string')->translate(['min' => (string) $constraint->value])
                : null,
            $constraint instanceof MaxLength => \mb_strlen($value) > \max(0, $constraint->value)
                ? $fail('validation.max.string')->translate(['max' => (string) \max(0, $constraint->value)])
                : null,
            $constraint instanceof Pattern, $constraint instanceof SlugFormat => $value !== '' && \preg_match('~' . \str_replace('~', '\\~', $constraint->regex) . '~', $value) !== 1
                ? $fail('validation.regex')->translate()
                : null,
            // No format accepts a whitespace-only string; an empty one is skipped, as the
            // Symfony format validators skip it.
            $constraint instanceof EmailFormat => $value !== '' ? $fail('validation.email')->translate() : null,
            $constraint instanceof UrlFormat => $value !== '' ? $fail('validation.url')->translate() : null,
            $constraint instanceof UuidFormat => $value !== '' ? $fail('validation.uuid')->translate() : null,
            $constraint instanceof UlidFormat => $value !== '' ? $fail('validation.ulid')->translate() : null,
            $constraint instanceof IpFormat => $value !== '' ? $fail(match ($constraint->version) {
                4 => 'validation.ipv4',
                6 => 'validation.ipv6',
                default => 'validation.ip',
            })->translate() : null,
            $constraint instanceof In => !$this->listed($value, $constraint->values) ? $fail('validation.in')->translate() : null,
            $constraint instanceof NotIn => $this->listed($value, $constraint->values) ? $fail('validation.not_in')->translate() : null,
            $constraint instanceof Min,
            $constraint instanceof ExclusiveMin,
            $constraint instanceof MultipleOf => $fail('validation.numeric')->translate(),
            $constraint instanceof MinItems,
            $constraint instanceof MaxItems,
            $constraint instanceof MinProperties,
            $constraint instanceof MaxProperties => $fail('validation.array')->translate(),
            default => null,
        };
    }

    /**
     * @param array<int, mixed> $values
     */
    private function listed(string $value, array $values): bool
    {
        return \in_array($value, $values, true);
    }
}
