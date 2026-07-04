<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects an explicit `null` on a **non-nullable** attribute that carries no typed value
 * rule of its own (e.g. a bare {@see \haddowg\JsonApi\Resource\Field\Str} /
 * {@see \haddowg\JsonApi\Resource\Field\Boolean} with no `minLength`/enum). Laravel's
 * typed rules (`string`, `numeric`, …) already reject a present `null`, but a field with
 * zero value constraints would otherwise let an explicit `null` sail through validation
 * and 500 in hydration (a `TypeError` on a non-nullable POPO property, a `NOT NULL`
 * `QueryException` on Eloquent).
 *
 * The {@see \haddowg\JsonApiLaravel\Validation\ResourceValidator} appends it to a field's
 * rule-set only when the field is neither nullable nor required (a required field's
 * `required` rule already rejects a present `null`, and a nullable field accepts it). As a
 * non-implicit rule it never fires for an omitted attribute, so a partial update that
 * simply leaves the field out is untouched — only a present, explicit `null` on a
 * non-nullable field is a clean `422` at that field's pointer.
 */
final class NotNull implements ValidationRule
{
    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if ($value === null) {
            $fail('validation.filled')->translate();
        }
    }
}
