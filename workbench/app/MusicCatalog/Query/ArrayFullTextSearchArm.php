<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Query;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterArmInterface;

/**
 * The in-memory arm for {@see FullTextSearch}: it returns a row predicate keeping any
 * object whose declared text fields contain the search term (case-insensitive substring).
 * The conformance witness for the reference {@see EloquentFullTextSearchArm} — both run
 * the same OR-of-substrings semantics so the custom filter behaves identically on either
 * provider.
 */
final class ArrayFullTextSearchArm implements ArrayFilterArmInterface
{
    public function supports(FilterInterface $filter): bool
    {
        return $filter instanceof FullTextSearch;
    }

    public function predicate(FilterInterface $filter, mixed $value): \Closure
    {
        \assert($filter instanceof FullTextSearch);
        $needle = \is_scalar($value) ? \strtolower((string) $value) : '';
        $fields = $filter->fields;

        return static function (mixed $row) use ($needle, $fields): bool {
            if ($needle === '' || !\is_object($row)) {
                return false;
            }

            foreach ($fields as $field) {
                $haystack = Accessor::get($row, $field);
                if (\is_scalar($haystack) && \str_contains(\strtolower((string) $haystack), $needle)) {
                    return true;
                }
            }

            return false;
        };
    }
}
