<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Query;

use haddowg\JsonApi\Resource\Filter\DescribedFilter;

/**
 * A demonstrator **custom full-text filter** (the extensible-filter seam, decision 14):
 * `filter[<key>]=term` keeps a resource whose ANY declared text field contains `term`
 * (case-insensitive substring). It is neither a `Where` nor any built-in — the built-in
 * handlers do not recognise it, so it runs only because a registered arm teaches the
 * provider to execute it: {@see ArrayFullTextSearchArm} on the in-memory witness and
 * {@see EloquentFullTextSearchArm} on the reference provider (a portable filter ships
 * both, and the pair stays behaviourally identical). The byte-compat twin of the Symfony
 * example's `FullTextSearch`.
 *
 * Implements {@see DescribedFilter} so the OpenAPI generator surfaces a meaningful
 * description on the `filter[<key>]` parameter rather than the generic default.
 */
final class FullTextSearch implements DescribedFilter
{
    /**
     * @param list<string> $fields the entity field names searched (OR-ed together)
     */
    private function __construct(
        private readonly string $key,
        public readonly array $fields,
    ) {}

    /**
     * @param list<string> $fields the entity field names searched (OR-ed together)
     */
    public static function make(string $key, array $fields): self
    {
        return new self($key, $fields);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function constraints(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return \sprintf('Case-insensitive substring search across %s.', \implode(', ', $this->fields));
    }
}
