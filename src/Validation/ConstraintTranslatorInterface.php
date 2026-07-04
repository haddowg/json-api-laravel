<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;

/**
 * Translates a custom {@see ConstraintInterface} — one outside core's built-in
 * vocabulary — into the `illuminate/validation` rules that enforce it. An application
 * defines its own typed constraint value object (attaching it to a field with
 * `constrain()`) and registers a translator for it by dropping the translator class in
 * a scanned discovery path, tagging a container binding with
 * {@see \haddowg\JsonApiLaravel\JsonApiServiceProvider::CONSTRAINT_TRANSLATOR_TAG}, or
 * registering it explicitly via `JsonApi::constraintTranslator()`.
 *
 * The {@see ConstraintTranslator} consults the registered translators — first
 * {@see supports()} match wins — for any constraint it does not translate itself, and
 * raises a clear error if none matches. This is the class-keyed extension point (PLAN
 * decision 6): a translator matches on the constraint's class, not a string id.
 */
interface ConstraintTranslatorInterface
{
    /**
     * Whether this translator handles the given constraint.
     */
    public function supports(ConstraintInterface $constraint): bool;

    /**
     * The `illuminate/validation` rules the given constraint translates to — a list
     * whose members are rule strings (`'email'`, `'min:3'`), rule objects
     * (`Rule::in([...])`), or invokable {@see \Illuminate\Contracts\Validation\ValidationRule}s.
     *
     * @return list<string|\Stringable|\Illuminate\Contracts\Validation\ValidationRule|\Illuminate\Contracts\Validation\Rule>
     */
    public function translate(ConstraintInterface $constraint): array;
}
