<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Query;

use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentFilterArmInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Eloquent push-down arm for {@see FullTextSearch}: it teaches
 * {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentFilterHandler} to compile the
 * custom filter into a grouped `OR`-of-`LIKE` predicate across the declared columns. The
 * request value is always bound as a query parameter (`where(... 'like', "%{$value}%")`);
 * the column names are server-declared on the filter, never client input, so the
 * `whereRaw`-free form is injection-safe.
 *
 * @implements EloquentFilterArmInterface<\Illuminate\Database\Eloquent\Model>
 */
final class EloquentFullTextSearchArm implements EloquentFilterArmInterface
{
    public function supports(FilterInterface $filter): bool
    {
        return $filter instanceof FullTextSearch;
    }

    public function apply(FilterInterface $filter, Builder $query, mixed $value): void
    {
        \assert($filter instanceof FullTextSearch);
        $term = \is_scalar($value) ? (string) $value : '';
        $fields = $filter->fields;

        $query->where(static function (Builder $group) use ($fields, $term): void {
            foreach ($fields as $field) {
                $group->orWhere($field, 'like', '%' . $term . '%');
            }
        });
    }
}
