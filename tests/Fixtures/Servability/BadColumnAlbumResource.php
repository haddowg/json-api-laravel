<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Servability;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A deliberately-broken read-only resource backed by the real `albums` Eloquent model, for
 * the servability failure-path suite (PLAN decision 11): it declares a sortable and a filter
 * pointing at columns the `albums` table does not have, and a relation naming no model
 * method — each of which `jsonapi:optimize` must report so the deploy fails rather than a
 * runtime 500.
 */
#[AsJsonApiResource(readOnly: true)]
final class BadColumnAlbumResource extends AbstractResource
{
    public static string $type = 'bad_albums';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->sortable(),
            // A relation naming no method on the Album model → flagged by the relation-method guard.
            BelongsTo::make('ghostRelation', 'artists'),
        ];
    }

    /**
     * @return list<\haddowg\JsonApi\Resource\Sort\SortInterface>
     */
    public function sorts(): array
    {
        // Points at a column the `albums` table does not have → flagged by the column guard.
        return [SortByField::make('bogusSort', 'nonexistent_sort_column')];
    }

    /**
     * @return list<\haddowg\JsonApi\Resource\Filter\FilterInterface>
     */
    public function filters(): array
    {
        // Points at a column the `albums` table does not have → flagged by the column guard.
        return [Where::make('bogusFilter', 'nonexistent_filter_column')];
    }
}
