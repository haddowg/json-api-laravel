<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Constraint;

use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApiLaravel\Validation\EntityConstraintInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Asserts that the value(s) of one or more fields are unique across the resource's
 * Eloquent table. Declared on a field with `->constrain(new UniqueEntity(['email']))`.
 *
 * Unlike the Symfony bundle's post-hydration seam, the Laravel bridge realises this as
 * a **pre-hydration** `Rule::unique`: the
 * {@see \haddowg\JsonApiLaravel\Validation\ResourceValidator} resolves the resource's
 * type → Eloquent model → table/key and builds
 * `Rule::unique(table, column)->ignore(currentId)` (the current record excluded on
 * update), so a duplicate is a `422` at the offending attribute's pointer before the
 * write ever reaches the persister. It is therefore Eloquent-only; on a POPO/in-memory
 * backing there is no table, so the constraint is inert (the post-hydration
 * {@see EntityConstraintInterface} seam remains available for a store-scanning custom
 * check).
 *
 * `scoped: true` checks uniqueness only among the rows the caller can see: the model's global
 * scopes apply, and a type served by a scoping provider counts only the rows that provider
 * returns, the same rules the related endpoint and relationship writes follow. It
 * also works for a type with no Eloquent model, by reading that type's provider. `using`
 * narrows the Eloquent query further, by a local scope name or a `fn (Builder $query)`
 * closure; `model` checks another model's table; `errorPath` reports the violation on another
 * attribute; `ignoreNull: false` treats a null value as a value to compare. Setting any of
 * these runs the check against the stored rows instead of `Rule::unique`. On update the row
 * being updated is always excluded.
 */
final readonly class UniqueEntity implements EntityConstraintInterface
{
    /**
     * @var list<string>
     */
    public array $fields;

    /**
     * @param list<string>                         $fields      the field(s) that together must be unique
     * @param bool                                 $scoped      compare only against the rows the caller can see
     * @param string|\Closure(Builder<Model>): mixed|null $using a local scope name, or a closure, that narrows the Eloquent query
     * @param string|null                          $errorPath   the attribute the violation is reported on (default: the field it is declared on)
     * @param bool                                 $ignoreNull  skip the check when any of the fields is null
     * @param class-string<Model>|null             $model       the model whose rows are compared (default: the resource's own)
     */
    public function __construct(
        array $fields,
        public ?string $message = null,
        public Context $context = new Context(),
        public bool $scoped = false,
        public string|\Closure|null $using = null,
        public ?string $errorPath = null,
        public bool $ignoreNull = true,
        public ?string $model = null,
    ) {
        $this->fields = $fields;
    }

    /**
     * Whether the check runs against the stored rows rather than as `Rule::unique`.
     */
    public function checksStoredRows(): bool
    {
        return $this->scoped || $this->using !== null || $this->errorPath !== null || !$this->ignoreNull || $this->model !== null;
    }

    public function context(): Context
    {
        return $this->context;
    }
}
