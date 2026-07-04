<?php

declare(strict_types=1);

namespace Workbench\App\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Url;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\EndsWith;
use haddowg\JsonApi\Resource\Filter\GreaterThanOrEqual;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\StartsWith;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `artists` resource type, re-themed from the Symfony bundle's example. Shared by
 * BOTH provider suites (in-memory and Eloquent), so its field columns are declared via
 * `storedAs()` where the wire name is camelCase but the storage column is snake_case
 * (`createdAt` → `created_at`) — the one declaration resolves off an in-memory POPO's
 * property AND an Eloquent model's cast attribute (blueprint §3.4).
 *
 * Phase 1 enriches it with the read query surface: a singular `filter[slug]` (a unique
 * match collapses to a single resource), substring/prefix name filters, an id-set
 * filter, and a deterministic default sort by `created_at` (so an unsorted, paginated
 * collection is stable). `readOnly: true` restricts it to the two fetch operations.
 */
#[AsJsonApiResource(readOnly: true)]
final class ArtistResource extends AbstractResource
{
    public static string $type = 'artists';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required()->maxLength(120)->sortable(),
            Str::make('slug')->sortable(),
            Url::make('website')->nullable(),
            Str::make('bio')->nullable()->maxLength(1000),
            // Computed: no backing column on the wire (computed() nulls it), so read the
            // value off the model's `track_count` — works for both the POPO (property)
            // and the Eloquent model (cast column) through the framework-neutral Accessor.
            Integer::make('trackCount')
                ->computed()
                ->readOnly()
                ->extractUsing(static function (mixed $model): int {
                    $value = \is_object($model) ? Accessor::get($model, 'track_count') : null;

                    return \is_numeric($value) ? (int) $value : 0;
                }),
            DateTime::make('createdAt')->storedAs('created_at')->readOnlyOnUpdate()->sortable(),
        ];
    }

    public function filters(): array
    {
        return [
            // WhereIdIn / WhereIdNotIn: filter[id]=1,2 narrows to (or excludes) the id set.
            WhereIdIn::make(),
            WhereIdNotIn::make('idNot', 'id'),
            // singular(): GET /artists?filter[slug]=radiohead collapses a unique match to
            // a single resource object (or null), not a collection.
            Where::make('slug')->singular(),
            // Case-insensitive substring / prefix / suffix name search (the `like` /
            // `starts` / `ends` string strategies — ASCII-case-insensitive on both
            // providers: in-memory stripos/str_ends_with, Eloquent a folded LIKE).
            Contains::make('nameContains', 'name'),
            StartsWith::make('nameStarts', 'name'),
            EndsWith::make('nameEnds', 'name'),
            // Null presence on the nullable `website` column (the request value is
            // ignored — presence decides the match).
            WhereNull::make('noWebsite', 'website'),
            WhereNotNull::make('hasWebsite', 'website'),
            // Numeric comparison on the non-null `track_count` column: `>=`, with the
            // numeric-coercion deserializer so the wire string binds as a number.
            GreaterThanOrEqual::make('minTracks', 'track_count'),
            // Structured numeric range over the NON-NULL `track_count` column, so the
            // inclusive min/max semantics are refereed without the null-vs-SQL
            // three-valued-logic impedance a nullable column would introduce (the
            // witness coerces null→0, SQL excludes NULLs — mirrors the bundle ranging
            // only its non-null `id`).
            Range::make('trackRange', 'track_count'),
        ];
    }

    /**
     * Oldest first by default (no `?sort`): keeps the unsorted collection — and its
     * pagination — deterministic. The directive names the `created_at` storage column
     * so both providers order by it. An explicit `?sort=` overrides this entirely.
     *
     * @return list<SortDirective>
     */
    public function defaultSort(): array
    {
        return [
            new SortDirective(SortByField::make('createdAt', 'created_at'), descending: false),
        ];
    }
}
