<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Constraint;

use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApiLaravel\Validation\EntityConstraintInterface;

/**
 * Asserts that the value(s) of one or more fields are unique across the resource's
 * Eloquent table. Declared on a field with `->constrain(new UniqueEntity(['email']))`.
 *
 * Unlike the Symfony bundle's post-hydration seam, the Laravel bridge realises this as
 * a **pre-hydration** `Rule::unique` (PLAN decision 6): the
 * {@see \haddowg\JsonApiLaravel\Validation\ResourceValidator} resolves the resource's
 * type → Eloquent model → table/key and builds
 * `Rule::unique(table, column)->ignore(currentId)` (the current record excluded on
 * update), so a duplicate is a `422` at the offending attribute's pointer before the
 * write ever reaches the persister. It is therefore Eloquent-only; on a POPO/in-memory
 * backing there is no table, so the constraint is inert (the post-hydration
 * {@see EntityConstraintInterface} seam remains available for a store-scanning custom
 * check).
 */
final readonly class UniqueEntity implements EntityConstraintInterface
{
    /**
     * @var list<string>
     */
    public array $fields;

    /**
     * @param list<string> $fields the field(s) that together must be unique
     */
    public function __construct(
        array $fields,
        public ?string $message = null,
        public Context $context = new Context(),
    ) {
        $this->fields = $fields;
    }

    public function context(): Context
    {
        return $this->context;
    }
}
