<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Constraint;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApi\Resource\Constraint\ProvidesJsonSchema;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The escape hatch for a validation rule core's constraint vocabulary does not model:
 * wrap one or more **native `illuminate/validation` rules** — a rule string
 * (`'min:3'`), a `Rule` builder (`Password::defaults()`), an invokable
 * {@see ValidationRule} — and attach them to a field (or filter) with core's
 * `constrain()`.
 *
 * ```php
 * Str::make('slug')->constrain(LaravelRules::make(['alpha_dash', 'min:3']));
 * ```
 *
 * Unlike defining a bespoke {@see \haddowg\JsonApi\Resource\Constraint\ConstraintInterface}
 * value object plus a class-keyed {@see \haddowg\JsonApiLaravel\Validation\ConstraintTranslatorInterface},
 * this needs no translator: the {@see \haddowg\JsonApiLaravel\Validation\ConstraintTranslator}
 * recognises it and passes the wrapped rules straight to Laravel's validator, so they
 * run in the same `422`-with-`source.pointer` pass as the translated core rules — on
 * write bodies and, because the filter-value validator shares the translator, on
 * `filter[…]` values too. The trade-off is portability: a `LaravelRules` couples the
 * field to Laravel, so prefer a core constraint when one exists and reach here only for
 * a genuinely Laravel-native rule.
 *
 * **Schema is opt-in.** A native rule is invisible to the generated OpenAPI / JSON Schema
 * by default (`contribute()` returns the schema unchanged), so it validates without
 * documenting. Declare the value schema it implies with {@see schema()} — a closure over
 * core's neutral {@see Schema} VO — when you want it in the document; keep it a neutral,
 * framework-independent fragment so the byte-compatible twin (the Symfony `NativeConstraints`
 * carrier) emits the identical schema.
 *
 * Scope it to a write context with {@see onCreate()} / {@see onUpdate()} (the default
 * applies on both); `constrain()` does not re-stamp the context, matching every other
 * custom constraint.
 */
final readonly class LaravelRules implements ProvidesJsonSchema
{
    /**
     * @var list<string|\Stringable|ValidationRule|Rule>
     */
    public array $rules;

    /**
     * @var \Closure(Schema): Schema|null
     */
    private ?\Closure $schema;

    /**
     * @param string|\Stringable|ValidationRule|Rule|list<string|\Stringable|ValidationRule|Rule> $rules
     */
    public function __construct(
        string|\Stringable|ValidationRule|Rule|array $rules,
        public Context $context = new Context(),
        ?\Closure $schema = null,
    ) {
        $this->rules = \is_array($rules) ? \array_values($rules) : [$rules];
        $this->schema = $schema;
    }

    /**
     * @param string|\Stringable|ValidationRule|Rule|list<string|\Stringable|ValidationRule|Rule> $rules
     */
    public static function make(string|\Stringable|ValidationRule|Rule|array $rules): self
    {
        return new self($rules);
    }

    public function onCreate(): self
    {
        return new self($this->rules, Context::onlyCreate(), $this->schema);
    }

    public function onUpdate(): self
    {
        return new self($this->rules, Context::onlyUpdate(), $this->schema);
    }

    /**
     * Declare the OpenAPI value schema this native rule implies. The closure receives
     * the field's accumulated {@see Schema} and returns it augmented
     * (`fn (Schema $s) => $s->withMinLength(3)`); without it the rule contributes
     * nothing to the document.
     *
     * @param \Closure(Schema): Schema $schema
     */
    public function schema(\Closure $schema): self
    {
        return new self($this->rules, $this->context, $schema);
    }

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        return $this->schema !== null ? ($this->schema)($schema) : $schema;
    }
}
