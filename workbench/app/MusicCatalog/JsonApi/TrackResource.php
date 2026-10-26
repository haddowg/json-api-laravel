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
use haddowg\JsonApi\Resource\Field\ArrayListBuilder;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
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
 * Its declared `preview` link is a link object with typed meta, left out for a track with
 * no duration.
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
            Id::make()->example('1'),
            Str::make('title')->example('Come Together')->required()->sortable(),
            Integer::make('trackNumber')->example(1)->storedAs('track_number')->required()->min(1)->sortable(),
            Integer::make('durationSeconds')->example(259)->storedAs('length_seconds')->guaranteed(),
            Boolean::make('explicit')->example(false)->guaranteed(),
            // No sensible default, so a create must send a non-empty list (required()
            // already rejects an empty one) and an update can't empty it.
            ArrayList::make('genres')->example(['rock'])
                ->required()
                ->onUpdate(static function (ArrayListBuilder $genres): void {
                    $genres->minItems(1);
                })
                ->each(new MinLength(1))
                ->uniqueItems(),
            Time::make('previewOffset')->example('00:00:30')->storedAs('preview_offset')->nullable(),
            Str::make('displayTitle')->example('1. Come Together')
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

    public function links(): array
    {
        return [
            DeclaredLink::make('preview')
                ->describedAs('A short audio preview of the track, valid until expiresAt.')
                ->type('audio/mpeg')
                ->example('https://cdn.example.com/previews/1.mp3')
                ->meta(
                    Str::make('format')->describedAs('Audio encoding of the preview.')->example('mp3'),
                    DateTime::make('expiresAt')->describedAs('When the preview URL stops working.')->example('2026-01-01T12:00:00+00:00'),
                )
                ->hrefUsing(function (mixed $track): ?string {
                    $duration = \is_object($track) ? Accessor::get($track, 'length_seconds') : null;
                    if (!\is_numeric($duration) || (int) $duration === 0) {
                        return null;
                    }

                    return 'https://cdn.example.com/previews/' . $this->getId($track) . '.mp3';
                })
                ->metaUsing(static fn(): array => ['format' => 'mp3', 'expiresAt' => now()->addHour()]),
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
