<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\EntitySeam;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApiLaravel\DataProvider\InMemoryStore;
use haddowg\JsonApiLaravel\Validation\ConstraintTranslatorInterface;

/**
 * The class-keyed extension translator for {@see UniqueNoteTitle} (PLAN decision 6): the
 * {@see \haddowg\JsonApiLaravel\Validation\ConstraintTranslator} delegates the unknown
 * constraint here and this returns the store-scanning {@see UniqueNoteTitleRule}. Proving
 * the extension-translator path AND the post-hydration entity seam end to end.
 */
final class UniqueNoteTitleTranslator implements ConstraintTranslatorInterface
{
    public function __construct(private readonly InMemoryStore $store) {}

    public function supports(ConstraintInterface $constraint): bool
    {
        return $constraint instanceof UniqueNoteTitle;
    }

    public function translate(ConstraintInterface $constraint): array
    {
        return [new UniqueNoteTitleRule($this->store)];
    }
}
