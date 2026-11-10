<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation;

use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Filter\AcceptsList;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\FilterFamilyInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\FilterMatcher;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApiLaravel\DataProvider\FilterPaths;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;

/**
 * Validates client-supplied `filter[<key>]` values against the **value constraints** a
 * filter declares ({@see FilterInterface::constraints()}, the `numeric()` / `integer()`
 * / `uuid()` / `boolean()` / `pattern()` / `constrain()` builders), *before* the filter
 * reaches a data provider. A violating value is a clean `400` {@see FilterValueInvalid}
 * (a bad query *parameter*, located by `source.parameter` on `filter[<key>]`) — turning
 * the provider's unhelpful default for a mistyped value (a silent non-match in memory /
 * on a loosely-typed database, or a driver error on a strict one) into a deliberate
 * client error.
 *
 * This is the filter-value twin of the {@see ResourceValidator}: it reuses the same
 * {@see ConstraintTranslator} bridge, so the filter shortcuts need no new translator
 * cases. Because validation is always-on in Laravel (illuminate/validation ships with
 * the framework), this runs on every collection request that carries a constrained
 * filter — unlike the Symfony bundle where it degrades to inert when the validator is
 * absent.
 *
 * Only the **client-supplied** values present in the request are validated, never a
 * filter's author-set `default()` — the handler hands this validator the raw requested
 * `filter` map, before core's `FilterDefaults::apply()` folds the defaults in, so a
 * server-declared default value is trusted and never re-checked.
 */
final class FilterValueValidator
{
    public function __construct(
        private readonly ValidationFactory $validatorFactory,
        private readonly ConstraintTranslator $translator,
    ) {}

    /**
     * Validates each client-supplied filter value present in `$requested` against the
     * matching declared filter's value constraints, throwing on the first filter whose
     * value violates them.
     *
     * Requested keys resolve through core's {@see FilterMatcher}, so a filter family's key
     * validates against the rules of the filter it resolves to. A requested key with no
     * matching declared filter is left untouched (the unrecognised-key `400` is the
     * provider's concern, raised later in the
     * {@see \haddowg\JsonApiLaravel\DataProvider\CriteriaApplier}); a filter that declares
     * no constraints and no list is skipped, so it costs nothing. A matched filter's
     * traversal path is resolved through `$paths` first, so a path ending on a relationship
     * validates each member against the related id's format when it declares no constraints.
     *
     * @param array<array-key, mixed>  $requested the request's raw `filter[<key>]` map
     * @param list<FilterInterface>    $filters   the declared filter vocabulary to match against
     * @param ?JsonApiRequestInterface $request   the request a filter family resolves a key against
     * @param ?FilterPaths             $paths     resolves a matched filter's traversal path against the filtered type's fields
     *
     * @throws FilterValueInvalid when a client-supplied value violates its filter's constraints, or a list filter's value is not a list it accepts
     */
    public function validate(array $requested, array $filters, ?JsonApiRequestInterface $request = null, ?FilterPaths $paths = null): void
    {
        foreach ($this->matched($requested, $filters, $request) as [$key, $filter]) {
            $filter = $paths?->resolve($filter) ?? $filter;
            $value = $requested[$key];
            $list = $filter instanceof AcceptsList ? $filter->listValue() : null;

            $rules = $this->rules($filter);

            // A declared list is read strictly, so a wrongly shaped value is a 400 even
            // when the filter declares no rules, and each member is validated on its own.
            $members = $list !== null
                ? \array_values(\array_filter($list->values($key, $value), static fn(mixed $member): bool => $member !== null))
                : null;

            $messages = [];
            if ($rules !== []) {
                foreach ($members ?? $this->members($filter, $value) as $member) {
                    $validator = $this->validatorFactory->make(['value' => $member], ['value' => $rules]);
                    foreach ($validator->errors()->all() as $message) {
                        $messages[] = $message;
                    }
                }
            }

            // A DateRange's shape Pattern is deliberately lenient on the calendar (it
            // admits `1997-13-99`), so a present bound is additionally checked for
            // temporal validity — an unparseable date is a clean 400 here rather than a
            // silent, provider-divergent non-match in the data layer.
            $messages = [...$messages, ...$this->dateRangeMessages($filter, $value)];

            if ($messages !== []) {
                throw new FilterValueInvalid($key, $messages);
            }

            // A relationship leaf decodes each wire id through the related type's encoder,
            // so an id it cannot decode is the same 400 before any query or gate runs.
            if ($filter instanceof WhereThroughIn && $filter->resolvedPath()?->endsOnRelation() === true) {
                $filter->storageValues($value);
            } elseif ($filter instanceof WhereThrough && $filter->resolvedPath()?->endsOnRelation() === true) {
                $filter->storageValue($value);
            }
        }
    }

    /**
     * The requested keys that match a declared filter, paired with it: exact keys in
     * declaration order, then the keys a filter family resolved, in request order. Only a
     * value the client actually sent is matched; a defaulted key is trusted and folded in
     * later.
     *
     * @param array<array-key, mixed> $requested
     * @param list<FilterInterface>   $filters
     *
     * @return list<array{string, FilterInterface}>
     */
    private function matched(array $requested, array $filters, ?JsonApiRequestInterface $request): array
    {
        $positions = [];
        foreach ($filters as $position => $filter) {
            if (!$filter instanceof FilterFamilyInterface) {
                $positions[$filter->key()] ??= $position;
            }
        }

        $matched = [];
        foreach (\array_keys($requested) as $key) {
            $key = (string) $key;
            $filter = FilterMatcher::match($filters, $key, $request);
            if ($filter !== null) {
                $matched[] = [$positions[$key] ?? \PHP_INT_MAX, $key, $filter];
            }
        }

        \usort($matched, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return \array_map(static fn(array $entry): array => [$entry[1], $entry[2]], $matched);
    }

    /**
     * The translated Laravel rules enforcing a filter's declared value constraints. A
     * filter's value constraints always apply (there is no create/update document context
     * for a query parameter), so — unlike the attribute bridge — they are not filtered by
     * `context()->appliesTo()`.
     *
     * @return list<string|\Stringable|\Illuminate\Contracts\Validation\ValidationRule|\Illuminate\Contracts\Validation\Rule>
     */
    private function rules(FilterInterface $filter): array
    {
        $rules = [];
        foreach ($filter->constraints() as $constraint) {
            // Filter-side validation passes no request: a widened when($value, $request)
            // condition receives a null request here (the documented MVP boundary).
            foreach ($this->translator->translate($constraint) as $rule) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * The scalar members of a filter value to validate individually: a single scalar is a
     * one-element list; an `IN`-style set is each array element, or — for a delimited
     * string — each split-and-trimmed token (mirroring the handlers, so a per-member rule
     * validates the exact tokens the provider queries against); a structured Range/DateRange
     * validates each present bound. A `null` member is skipped.
     *
     * @return list<mixed>
     */
    private function members(FilterInterface $filter, mixed $value): array
    {
        if ($filter instanceof Range) {
            return $this->rangeMembers($value);
        }

        if (\is_array($value)) {
            return \array_values(\array_filter($value, static fn(mixed $member): bool => $member !== null));
        }

        $delimiter = $this->delimiterFor($filter);
        if ($delimiter !== null && \is_string($value)) {
            $separator = $delimiter !== '' ? $delimiter : ',';

            return \array_values(\array_map('\trim', \explode($separator, $value)));
        }

        return [$value];
    }

    /**
     * The present, non-blank bounds of a {@see Range} value — the nested `{min?, max?}`
     * array. A blank (`''`) or absent bound is open-ended, treated as absent and never
     * validated; a non-array value is a no-op in both handlers.
     *
     * @return list<mixed>
     */
    private function rangeMembers(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $members = [];
        foreach (['min', 'max'] as $key) {
            if (!\array_key_exists($key, $value)) {
                continue;
            }

            /** @var mixed $bound */
            $bound = $value[$key];
            if ($bound === null || $bound === '') {
                continue;
            }

            $members[] = $bound;
        }

        return $members;
    }

    /**
     * Temporal-validity messages for a {@see DateRange}'s present bounds. Its shape
     * Pattern is lenient on the calendar (a regex cannot reject `1997-13-99`), so each
     * present bound is additionally run through the filter's own date deserializer; a
     * bound that does not coerce to a `\DateTimeInterface` yields one message. A
     * non-`DateRange` filter contributes nothing.
     *
     * @return list<string>
     */
    private function dateRangeMessages(FilterInterface $filter, mixed $value): array
    {
        if (!$filter instanceof DateRange) {
            return [];
        }

        $deserialize = $filter->deserialize;
        if ($deserialize === null) {
            return [];
        }

        $messages = [];
        foreach ($this->rangeMembers($value) as $bound) {
            if (!$deserialize($bound) instanceof \DateTimeInterface) {
                $messages[] = 'This value is not a valid date.';
            }
        }

        return $messages;
    }

    /**
     * The delimiter an `IN`-style filter splits its value on (`','` by default), or `null`
     * for a single-value filter — resolved from the filter's public `delimiter` property,
     * present only on the set-valued filters.
     */
    private function delimiterFor(FilterInterface $filter): ?string
    {
        if (!\property_exists($filter, 'delimiter')) {
            return null;
        }

        /** @var mixed $delimiter */
        $delimiter = $filter->delimiter;

        return \is_string($delimiter) ? $delimiter : '';
    }
}
