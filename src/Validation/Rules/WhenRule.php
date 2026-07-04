<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

/**
 * Enforces the **value** rules of a core {@see \haddowg\JsonApi\Resource\Constraint\When}
 * — an arbitrary PHP condition gating a set of inner constraints. Laravel's own
 * conditional validation lives at the validator level (`$validator->sometimes()`), but
 * a composable single rule keeps `When` nestable and translatable in isolation: it
 * evaluates the condition against the value (and the inbound request, core ADR 0080)
 * and, only when it holds, validates the value against the translated inner rules.
 *
 * Presence rules ({@see \haddowg\JsonApi\Resource\Constraint\Required} /
 * {@see \haddowg\JsonApi\Resource\Constraint\Nullable}) nested in a `When` are resolved
 * by the {@see \haddowg\JsonApiLaravel\Validation\ResourceValidator} during presence
 * resolution (a conditionally-required field, ADR 0084), not here — this rule validates
 * only the inner *value* rules.
 */
final class WhenRule implements ValidationRule
{
    /**
     * @param \Closure(mixed, ?JsonApiRequestInterface): bool $condition
     * @param list<mixed>                                     $rules     the translated inner value rules
     */
    public function __construct(
        private readonly \Closure $condition,
        private readonly array $rules,
        private readonly ?JsonApiRequestInterface $request = null,
    ) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (($this->condition)($value, $this->request) !== true) {
            return;
        }

        if ($this->rules === []) {
            return;
        }

        // Validate under a FIXED, non-dotted key (like {@see EachElement} does) rather than
        // the outer `$attribute`: for a {@see \haddowg\JsonApi\Resource\Field\Map} child the
        // outer attribute is dotted (`address.postcode`), and Laravel would read the rule
        // key as a nested path into the flat data array — never finding the value, silently
        // skipping every inner rule. A fixed key sidesteps the dotted-key ambiguity so the
        // inner pass never depends on the outer attribute name.
        $validator = Validator::make(
            ['value' => $value],
            ['value' => $this->rules],
        );

        foreach ($validator->errors()->all() as $message) {
            $fail($message);
        }
    }
}
