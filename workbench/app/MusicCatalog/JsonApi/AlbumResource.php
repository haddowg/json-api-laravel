<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Link\ActionLink;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use Workbench\App\MusicCatalog\Domain\Album as AlbumDomain;
use Workbench\App\MusicCatalog\Model\AlbumStatus;
use Workbench\App\MusicCatalog\Models\Album as AlbumModel;
use Workbench\App\MusicCatalog\Query\FullTextSearch;

/**
 * The `albums` resource type (music-catalog domain) — the multi-server + richest witness:
 * exposed on BOTH the `default` and `admin` servers, tagged `Catalog`, with a backed-enum
 * `status`, a directional `CompareField` (availableUntil > availableFrom), a JSON `Map`
 * (`releaseInfo`), a relation-scoped to-one filter on `artist`, and a counting/filtered/
 * sorted `tracks` HasMany. Default include: `artist`. Default sort: `releasedAt` DESC.
 * Its declared `store` link renders as a bare URL.
 */
#[AsJsonApiResource(server: ['default', 'admin'], tags: ['Catalog'])]
final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make()->example('1'),
            Str::make('title')->example('Abbey Road')->required()->maxLength(200)->sortable(),
            Decimal::make('averageRating')->example(4.5)->storedAs('average_rating')->readOnly()->nullable(),
            Str::make('artwork')->example('/uploads/artwork/1.png')->readOnly()->nullable(),
            DateTime::make('releasedAt')->example('1969-09-26T00:00:00+00:00')
                ->storedAs('released_at')
                ->before(static fn(): \DateTimeImmutable => new \DateTimeImmutable())
                ->useTimezone('UTC')
                ->sortable(),
            Boolean::make('explicit')->example(false)->guaranteed(),
            Str::make('status')->example('released')
                ->enum(AlbumStatus::class)
                ->sortable()
                ->describedAs('Where the album sits in its release lifecycle.')
                ->guaranteed(),
            Date::make('availableFrom')->example('2024-01-01')->storedAs('available_from')->nullable(),
            Date::make('availableUntil')->example('2026-12-31')
                ->storedAs('available_until')
                ->nullable()
                ->compareWith('availableFrom', Comparison::GreaterThan),
            // The read hook shapes the value itself, so the map needs its own example.
            Map::make('releaseInfo')->example(['label' => 'Apple Records', 'catalogueNumber' => 'PCS 7088'])->nullable()->fields(
                Str::make('label')->example('Apple Records'),
                Str::make('catalogueNumber')->example('PCS 7088')->readOnly(),
            )->serializeUsing(static function (mixed $model): mixed {
                $info = \is_object($model) ? Accessor::get($model, 'release_info') : null;

                return $info === [] ? null : $info;
            })->fillUsing(static function (mixed $model, mixed $value): mixed {
                if (\is_object($model)) {
                    $info = null;
                    if (\is_array($value)) {
                        $info = [];
                        foreach ($value as $key => $item) {
                            $info[(string) $key] = $item;
                        }
                    }
                    Accessor::set($model, 'release_info', $info);
                }

                return $model;
            }),
            BelongsTo::make('artist', 'artists')
                ->withFilters(Where::make('name', 'name')),
            HasMany::make('tracks', 'tracks')
                ->paginate(PagePaginator::make()->withDefaultPerPage(2))
                ->withFilters(Where::make('longerThan', 'length_seconds', '>')->integer())
                ->withSorts(SortByField::make('duration', 'length_seconds'))
                ->countable(),
        ];
    }

    /**
     * The `store` link, and the `download` action as a link only while the album is
     * released: the action builds the href, the condition decides when it shows.
     */
    public function links(): array
    {
        return [
            DeclaredLink::make('store')
                ->describedAs('The album\'s page in the store.')
                ->example('https://store.example.com/albums/1')
                ->hrefUsing(fn(mixed $album): string => 'https://store.example.com/albums/' . $this->getId($album)),
            ActionLink::for('download')
                ->when(static fn(AlbumModel|AlbumDomain $album, JsonApiRequestInterface $request): bool => $album->status === AlbumStatus::Released->value)
                ->describedAs('A short-lived link to the album as CSV, present while the album is released.'),
        ];
    }

    public function filters(): array
    {
        return [
            WhereHas::make('tracks'),
            // Declares no value constraint on purpose: `name` is a column on the related
            // Artist and core will not derive a narrower type through a relationship path,
            // so the value documents as the plain `string` a query
            // parameter is on the wire. A constraint would narrow it — but every
            // constraint that fits asserts something an artist name is not.
            WhereThrough::make('artist.name'),
            Contains::make('title'),
            Range::make('rating', 'average_rating'),
            DateRange::make('releasedAt', 'released_at'),
            FullTextSearch::make('q', ['title']),
        ];
    }

    /**
     * @return list<SortDirective>
     */
    public function defaultSort(): array
    {
        return [
            new SortDirective(SortByField::make('releasedAt', 'released_at'), descending: true),
        ];
    }

    /**
     * Default includes realised as this override: `GET /albums/1` with no `?include`
     * yields the artist in `included`; an explicit `?include` suppresses it.
     *
     * @return list<string>
     */
    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return ['artist'];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof AlbumModel || $object instanceof AlbumDomain ? 'albums' : '';
    }
}
