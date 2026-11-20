<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Filter\FilterBuilderInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Albums again, declaring one pivot path the boot or the warm-up refuses, chosen by
 * {@see $declaration}.
 */
#[AsJsonApiResource(readOnly: true)]
final class TpBrokenResource extends AbstractResource
{
    public static string $type = 'tpBroken';

    public static string $declaration = 'none';

    public function fields(): array
    {
        $tracks = HasMany::make('tracks', 'tpTracks');
        $listed = BelongsToMany::make('listedTracks', 'tpTracks');

        return [
            Id::make(),
            self::$declaration === 'plain-relation-pivot' ? $tracks->withFilters(Where::make('x', 'title')->through('pivot')) : $tracks,
            match (self::$declaration) {
                'leaf-not-pivot-column' => $listed->fields(Integer::make('position'), Integer::make('missing'))->withFilters(Where::make('x', 'missing')->through('pivot')),
                'mid-path-pivot-sort' => $listed->fields(Integer::make('position')),
                default => $listed,
            },
            // A pivot model with no stored pivot fields declared: core accepts a path past its
            // pivot row, which the Eloquent provider reads no pivot rows of.
            BelongsToMany::make('entryTracks', 'tpTracks')->through(TpEntry::class)->withFilters(...self::legacyPivotFilters()),
            self::$declaration === 'morph-to-many-pivot'
                ? MorphToMany::make('taggedTracks', ['tpTracks'])->identifiedBy(static fn(object $album): array => [])->fields(Integer::make('position'))->withFilters(Where::make('x', 'position')->through('pivot'))
                : MorphToMany::make('taggedTracks', ['tpTracks']),
        ];
    }

    public function filters(): array
    {
        return match (self::$declaration) {
            'leading-pivot-on-collection' => [Where::make('x', 'position')->through('pivot')],
            'pivot-after-plain-to-many' => [Where::make('x', 'title')->through('listedTracks.pivot')],
            'unknown-pivot-row-relation' => [Where::make('x', 'name')->through('tracks.playlists.pivot.ghost')],
            'pivot-row-relation-no-type' => [Where::make('x', 'name')->through('tracks.playlists.pivot.owner')],
            'pivot-row-relation-several-types' => [Where::make('x', 'name')->through('tracks.playlists.pivot.addedBy')],
            'pivot-row-morph-to' => [Where::make('x', 'name')->through('tracks.playlists.pivot.subject')],
            'association-class-without-stored-fields' => [Where::make('x', 'name')->through('entryTracks.pivot.addedBy')],
            default => [],
        };
    }

    /**
     * A deprecated `pivot.`-prefixed filter going on from the pivot row to the owner, which no
     * type serves unless a declaration maps two.
     *
     * @return list<FilterInterface|FilterBuilderInterface>
     */
    private static function legacyPivotFilters(): array
    {
        return match (self::$declaration) {
            'legacy-through-untyped', 'legacy-through-several' => [WhereThrough::make('ownerName', 'pivot.owner.name')],
            'legacy-through-in-untyped' => [WhereThroughIn::make('owner', 'pivot.owner')],
            'legacy-has-untyped' => [WhereHas::make('owned', 'pivot.owner')],
            'legacy-doesnt-have-untyped' => [WhereDoesntHave::make('unowned', 'pivot.owner')],
            'legacy-grouped-untyped' => [WhereAny::make('search', WhereThrough::make('ownerSearch', 'pivot.owner.name'))],
            default => [],
        };
    }

    public function sorts(): array
    {
        return self::$declaration === 'mid-path-pivot-sort' ? [SortByField::make('x', 'position')->through('listedTracks.pivot')] : [];
    }
}
