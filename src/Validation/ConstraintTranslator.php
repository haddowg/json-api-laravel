<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\After;
use haddowg\JsonApi\Resource\Constraint\AtLeastOneOf;
use haddowg\JsonApi\Resource\Constraint\Before;
use haddowg\JsonApi\Resource\Constraint\Between;
use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Each;
use haddowg\JsonApi\Resource\Constraint\EmailFormat;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMax;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMin;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Constraint\IpFormat;
use haddowg\JsonApi\Resource\Constraint\Max;
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
use haddowg\JsonApi\Resource\Constraint\Sequentially;
use haddowg\JsonApi\Resource\Constraint\SlugFormat;
use haddowg\JsonApi\Resource\Constraint\UlidFormat;
use haddowg\JsonApi\Resource\Constraint\UniqueItems;
use haddowg\JsonApi\Resource\Constraint\UrlFormat;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApi\Resource\Constraint\When;
use haddowg\JsonApiLaravel\Validation\Rules\AfterDate;
use haddowg\JsonApiLaravel\Validation\Rules\AtLeastOneOf as AtLeastOneOfRule;
use haddowg\JsonApiLaravel\Validation\Rules\BeforeDate;
use haddowg\JsonApiLaravel\Validation\Rules\BetweenDates;
use haddowg\JsonApiLaravel\Validation\Rules\DistinctArray;
use haddowg\JsonApiLaravel\Validation\Rules\EachElement;
use haddowg\JsonApiLaravel\Validation\Rules\GreaterThan;
use haddowg\JsonApiLaravel\Validation\Rules\LessThan;
use haddowg\JsonApiLaravel\Validation\Rules\UuidVersion;
use haddowg\JsonApiLaravel\Validation\Rules\WhenRule;
use Illuminate\Validation\Rule;

/**
 * Translates a core {@see ConstraintInterface} value object into the
 * `illuminate/validation` rule(s) that enforce it. Core declares constraints as
 * metadata and never executes them; this is the always-on adapter (PLAN decision 6)
 * that gives the vocabulary teeth under Laravel's validator, producing real Laravel
 * rules whose messages are Laravel's localizable strings.
 *
 * Presence and nullability ({@see \haddowg\JsonApi\Resource\Constraint\Required} /
 * {@see \haddowg\JsonApi\Resource\Constraint\Nullable}) are *not* translated here —
 * they are resolved by the {@see ResourceValidator} against the create/update context
 * into a field's `required`/`sometimes`/`nullable` wrapper. Likewise
 * {@see \haddowg\JsonApi\Resource\Constraint\CompareField} (cross-field, evaluated at
 * the document level) and any {@see EntityConstraintInterface} (validated against the
 * hydrated entity, or — for the built-in {@see Constraint\UniqueEntity} — folded into a
 * pre-hydration `Rule::unique`).
 *
 * The composition and closure-carrying constraints have no single stock Laravel rule,
 * so each translates to a shipped invokable {@see \Illuminate\Contracts\Validation\ValidationRule}
 * (`Rules/*`): `Each` → {@see EachElement}, `When` → {@see WhenRule}, `AtLeastOneOf` →
 * {@see AtLeastOneOfRule}, the date bounds → {@see AfterDate}/{@see BeforeDate}/{@see BetweenDates},
 * the exclusive numeric bounds → {@see GreaterThan}/{@see LessThan}, `UniqueItems` →
 * {@see DistinctArray}; `Sequentially` maps to a `bail`-led ruleset.
 *
 * A constraint outside this built-in vocabulary is delegated to the registered
 * {@see ConstraintTranslatorInterface}s (first {@see ConstraintTranslatorInterface::supports()}
 * match wins) — the class-keyed extension point applications use for their own
 * constraint value objects; if none matches, translation fails loud.
 */
final class ConstraintTranslator
{
    /**
     * @var list<ConstraintTranslatorInterface>
     */
    private readonly array $extensionTranslators;

    /**
     * @param iterable<ConstraintTranslatorInterface> $extensionTranslators in priority order
     */
    public function __construct(iterable $extensionTranslators = [])
    {
        $this->extensionTranslators = \is_array($extensionTranslators)
            ? \array_values($extensionTranslators)
            : \iterator_to_array($extensionTranslators, false);
    }

    /**
     * The `illuminate/validation` rules enforcing one core constraint.
     *
     * The optional `$request` is threaded only into the closure-carrying {@see When}
     * (its condition is widened to `($value, ?$request)`, core ADR 0080); every other
     * constraint is request-independent. A `null` request — the filter-side validator
     * and the id-format check pass none — invokes the condition with `null` as its
     * second argument, so an existing `fn($value)` closure keeps binding unchanged.
     *
     * @return list<string|\Stringable|\Illuminate\Contracts\Validation\ValidationRule|\Illuminate\Contracts\Validation\Rule>
     *
     * @throws \LogicException for a constraint the bridge does not translate
     */
    public function translate(ConstraintInterface $constraint, ?JsonApiRequestInterface $request = null): array
    {
        return match (true) {
            $constraint instanceof In => [Rule::in($this->scalarList($constraint->values))],
            $constraint instanceof NotIn => [Rule::notIn($this->scalarList($constraint->values))],
            $constraint instanceof Min => ['numeric', 'min:' . $this->number($constraint->value)],
            $constraint instanceof Max => ['numeric', 'max:' . $this->number($constraint->value)],
            $constraint instanceof ExclusiveMin => [new GreaterThan($constraint->value)],
            $constraint instanceof ExclusiveMax => [new LessThan($constraint->value)],
            $constraint instanceof MultipleOf => ['numeric', 'multiple_of:' . $this->number($constraint->value)],
            $constraint instanceof MinLength => ['string', 'min:' . \max(0, $constraint->value)],
            $constraint instanceof MaxLength => ['string', 'max:' . \max(0, $constraint->value)],
            $constraint instanceof MinItems => ['array', 'min:' . \max(0, $constraint->value)],
            $constraint instanceof MaxItems => ['array', 'max:' . \max(0, $constraint->value)],
            $constraint instanceof MinProperties => ['array', 'min:' . \max(0, $constraint->value)],
            $constraint instanceof MaxProperties => ['array', 'max:' . \max(0, $constraint->value)],
            $constraint instanceof UniqueItems => [new DistinctArray()],
            $constraint instanceof EmailFormat => [$constraint->strict ? 'email:strict' : 'email'],
            $constraint instanceof UrlFormat => [$this->url($constraint)],
            $constraint instanceof UuidFormat => [$this->uuid($constraint)],
            $constraint instanceof UlidFormat => ['ulid'],
            $constraint instanceof IpFormat => [match ($constraint->version) {
                4 => 'ipv4',
                6 => 'ipv6',
                default => 'ip',
            }],
            $constraint instanceof Pattern => ['regex:' . $this->delimit($constraint->regex)],
            $constraint instanceof SlugFormat => ['regex:' . $this->delimit($constraint->regex)],
            $constraint instanceof Each => [new EachElement($this->translateAll($constraint->constraints, $request))],
            $constraint instanceof Sequentially => ['bail', ...$this->translateAll($constraint->constraints, $request)],
            $constraint instanceof AtLeastOneOf => [new AtLeastOneOfRule($this->alternatives($constraint->constraints, $request))],
            $constraint instanceof When => [new WhenRule($constraint->condition, $this->translateValueRules($constraint, $request), $request)],
            $constraint instanceof After => [new AfterDate($constraint->bound)],
            $constraint instanceof Before => [new BeforeDate($constraint->bound)],
            $constraint instanceof Between => [new BetweenDates($constraint->min, $constraint->max)],
            default => $this->translateExtension($constraint),
        };
    }

    /**
     * Translates a list of constraints, flattening the per-constraint results (used for
     * the inner constraints of {@see Each}/{@see Sequentially}).
     *
     * @param list<ConstraintInterface> $constraints
     *
     * @return list<string|\Stringable|\Illuminate\Contracts\Validation\ValidationRule|\Illuminate\Contracts\Validation\Rule>
     */
    private function translateAll(array $constraints, ?JsonApiRequestInterface $request = null): array
    {
        $translated = [];
        foreach ($constraints as $constraint) {
            foreach ($this->translate($constraint, $request) as $rule) {
                $translated[] = $rule;
            }
        }

        return $translated;
    }

    /**
     * The inner **value** rules of a {@see When}: its presence markers
     * ({@see \haddowg\JsonApi\Resource\Constraint\Required} /
     * {@see \haddowg\JsonApi\Resource\Constraint\Nullable}) carry no value rule — they
     * are resolved by the {@see ResourceValidator} during presence resolution (a
     * conditionally-required field, ADR 0084) — so they are filtered out before
     * translating the rest, which would otherwise fail loud (they have no value-rule
     * translation).
     *
     * @return list<string|\Stringable|\Illuminate\Contracts\Validation\ValidationRule|\Illuminate\Contracts\Validation\Rule>
     */
    private function translateValueRules(When $constraint, ?JsonApiRequestInterface $request): array
    {
        $valueRules = \array_values(\array_filter(
            $constraint->constraints,
            static fn(ConstraintInterface $inner): bool
                => !$inner instanceof \haddowg\JsonApi\Resource\Constraint\Required
                && !$inner instanceof \haddowg\JsonApi\Resource\Constraint\Nullable,
        ));

        return $this->translateAll($valueRules, $request);
    }

    /**
     * Translates each alternative of an {@see AtLeastOneOf} to its own rule-set (the
     * flattened translation of that alternative).
     *
     * @param list<ConstraintInterface> $alternatives
     *
     * @return list<list<string|\Stringable|\Illuminate\Contracts\Validation\ValidationRule|\Illuminate\Contracts\Validation\Rule>>
     */
    private function alternatives(array $alternatives, ?JsonApiRequestInterface $request = null): array
    {
        $translated = [];
        foreach ($alternatives as $alternative) {
            $translated[] = $this->translate($alternative, $request);
        }

        return $translated;
    }

    /**
     * Delegates a constraint outside the built-in vocabulary to the first registered
     * {@see ConstraintTranslatorInterface} that supports it.
     *
     * @return list<string|\Stringable|\Illuminate\Contracts\Validation\ValidationRule|\Illuminate\Contracts\Validation\Rule>
     */
    private function translateExtension(ConstraintInterface $constraint): array
    {
        foreach ($this->extensionTranslators as $translator) {
            if ($translator->supports($constraint)) {
                return $translator->translate($constraint);
            }
        }

        throw new \LogicException(\sprintf(
            'No translator is registered for the JSON:API constraint %s. Implement a %s and register it, or use a built-in constraint.',
            $constraint::class,
            ConstraintTranslatorInterface::class,
        ));
    }

    private function url(UrlFormat $constraint): string
    {
        $schemes = \array_map('\strval', \array_values($constraint->allowedSchemes));

        return $schemes === [] ? 'url' : 'url:' . \implode(',', $schemes);
    }

    /**
     * @return string|\Illuminate\Contracts\Validation\ValidationRule
     */
    private function uuid(UuidFormat $constraint): string|\Illuminate\Contracts\Validation\ValidationRule
    {
        $version = $constraint->version;
        if ($version === null || $version < 1 || $version > 8) {
            return 'uuid';
        }

        return new UuidVersion($version);
    }

    /**
     * Wraps a core regex (an ECMA-262 source with no delimiters) as a PCRE pattern,
     * escaping the delimiter where it appears literally. The pattern is passed as its
     * OWN rule-array element (never a pipe-joined string), so a `|` inside it is never
     * mistaken for a rule separator.
     */
    private function delimit(string $regex): string
    {
        return '~' . \str_replace('~', '\\~', $regex) . '~';
    }

    /**
     * Formats an int|float rule parameter without locale/scientific notation so
     * `multiple_of:0.5` / `min:3` bind cleanly.
     */
    private function number(int|float $value): string
    {
        if (\is_int($value)) {
            return (string) $value;
        }

        $formatted = \rtrim(\rtrim(\sprintf('%.15F', $value), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return list<mixed>
     */
    private function scalarList(array $values): array
    {
        return \array_values($values);
    }
}
