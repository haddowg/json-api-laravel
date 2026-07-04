<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\EntitySeam;

use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApiLaravel\Validation\EntityConstraintInterface;

/**
 * A custom post-hydration constraint: the note's title must be unique across the
 * in-memory store. It is the store-scanning counterpart to the Eloquent-only
 * {@see \haddowg\JsonApiLaravel\Validation\Constraint\UniqueEntity} — a genuinely
 * post-hydration check (it needs the hydrated entity + a store the request cannot see),
 * so it flows through the retained {@see EntityConstraintInterface} seam and the
 * class-keyed extension-translator path ({@see UniqueNoteTitleTranslator}).
 */
final readonly class UniqueNoteTitle implements EntityConstraintInterface
{
    public function __construct(public Context $context = new Context()) {}

    public function context(): Context
    {
        return $this->context;
    }
}
