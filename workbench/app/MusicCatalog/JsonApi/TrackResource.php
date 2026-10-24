<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\OffsetPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use Workbench\App\MusicCatalog\Domain\Track as TrackDomain;
use Workbench\App\MusicCatalog\Models\Track as TrackModel;
use Workbench\App\MusicCatalog\Query\FullTextSearch;
use Workbench\App\MusicCatalog\Support\CatalogConfig;

/**
 * The `tracks` resource type (music-catalog domain). An `ArrayList` with per-item rules
 * (`genres`), a `storedAs` rename (`durationSeconds` ← `length_seconds`), a `Time`
 * (`previewOffset`), a computed `displayTitle`, the `album` to-one and the plain
 * `belongsToMany` `playlists` (eager linkage via `withData()`, prohibiting full replace).
 *
 * It is registered on both servers because {@see AlbumResource} is, and an album's
 * `tracks` relation exposes `GET /albums/{id}/tracks` — that endpoint returns track
 * resource objects, so `tracks` has to exist on every server that serves albums.
 *
 * It is also the pagination-menu witness: {@see pagination()} offers page, cursor and
 * offset strategies on the primary `tracks` collection, and the client picks one per
 * request.
 *
 * (The Symfony example additionally binds a hand-written `TrackSerializer` with a DI
 * constructor arg via `serializer:`; the Laravel `AsJsonApiResource` attribute does not
 * yet carry a serializer override, so the default serializer renders the same wire shape.)
 */
#[AsJsonApiResource(server: ['default', 'admin'])]
final class TrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->sortable(),
            Integer::make('trackNumber')->storedAs('track_number')->min(1)->sortable()->guaranteed(),
            Integer::make('durationSeconds')->storedAs('length_seconds')->guaranteed(),
            Boolean::make('explicit')->guaranteed(),
            ArrayList::make('genres')
                ->minItems(1)
                ->each(new MinLength(1))
                ->uniqueItems()
                ->guaranteed(),
            Time::make('previewOffset')->storedAs('preview_offset')->nullable(),
            Str::make('displayTitle')
                ->computed()
                ->readOnly()
                ->extractUsing(static function (mixed $track): string {
                    if (!\is_object($track)) {
                        return '';
                    }
                    $number = Accessor::get($track, 'track_number');
                    $title = Accessor::get($track, 'title');

                    return \sprintf('%d. %s', \is_numeric($number) ? (int) $number : 0, \is_string($title) ? $title : '');
                }),
            BelongsTo::make('album', 'albums'),
            BelongsToMany::make('playlists', 'playlists')
                ->cannotReplace()
                ->countable()
                ->withData(),
        ];
    }

    /**
     * A {@see MultiPaginator} menu for `GET /tracks`. `page[kind]` names a strategy; a
     * key only one strategy reads (`after`/`before` for cursor, `offset`/`limit` for
     * offset) selects it without a kind; anything else falls back to `page`. The page
     * arm repeats the server default (count-free, capped at the configured max), so a
     * request that doesn't pick a strategy gets the same response as before the menu.
     *
     * The album and playlist relations that reach `tracks` declare their own
     * `paginate()`, so the menu stays on this collection alone.
     */
    public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
    {
        return MultiPaginator::make(
            PagePaginator::make()->withMaxPerPage(CatalogConfig::MAX_PER_PAGE),
            CursorPaginator::make()->withMaxPerPage(CatalogConfig::MAX_PER_PAGE),
            OffsetPaginator::make()->withMaxPerPage(CatalogConfig::MAX_PER_PAGE),
        )->default('page');
    }

    public function filters(): array
    {
        return [
            Where::make('title', 'title', 'like'),
            Where::make('explicit')->asBoolean()->default(false)->boolean(),
            WhereIn::make('genres'),
            FullTextSearch::make('q', ['title']),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof TrackModel || $object instanceof TrackDomain ? 'tracks' : '';
    }
}
