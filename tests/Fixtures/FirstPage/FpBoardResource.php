<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `fp-boards`, whose to-manys cover each way a rendered relationship is paginated: `cards`
 * two a page and countable, `featured` the same cards rendered with `withData()`, `labels` a
 * many-to-many two a page, `cursorLabels` the same labels two to a cursor page, `newestCards`
 * the cards in the order their model relation gives them, two a page, `allCards` declared `withoutPagination()`, `pocketCards`, which
 * exposes no endpoint, and two polymorphic sets two a page: `pins`, computed by a closure,
 * and `tagged`, from stored pairs.
 */
final class FpBoardResource extends AbstractResource
{
    public static string $type = 'fp-boards';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('cards', 'fp-cards')->paginate(PagePaginator::make()->withDefaultPerPage(2))->countable(),
            HasMany::make('featured', 'fp-cards')->storedAs('cards')->paginate(PagePaginator::make()->withDefaultPerPage(2))->withData(),
            BelongsToMany::make('labels', 'fp-labels')->paginate(PagePaginator::make()->withDefaultPerPage(2)),
            BelongsToMany::make('cursorLabels', 'fp-labels')->storedAs('labels')->paginate(CursorPaginator::make()->withDefaultSize(2)),
            HasMany::make('newestCards', 'fp-cards')->paginate(PagePaginator::make()->withDefaultPerPage(2)),
            HasMany::make('allCards', 'fp-cards')->storedAs('cards')->withoutPagination(),
            // Every label then every card, a polymorphic set its own closure computes.
            MorphToMany::make('pins', ['fp-labels', 'fp-cards'])->readOnly()
                ->extractUsing(static fn(mixed $board): array => $board instanceof FpBoard || $board instanceof FpBoardRecord ? [...self::members($board->labels), ...self::members($board->cards)] : [])
                ->paginate(PagePaginator::make()->withDefaultPerPage(2)),
            MorphToMany::make('tagged', ['fp-labels', 'fp-cards'])->readOnly()
                ->identifiedBy(static fn(mixed $board): array => $board instanceof FpBoard || $board instanceof FpBoardRecord ? $board->taggedPairs() : [])
                ->paginate(PagePaginator::make()->withDefaultPerPage(2)),
            HasMany::make('pocketCards', 'fp-cards')->storedAs('cards')->paginate(PagePaginator::make()->withDefaultPerPage(2))
                ->withoutRelatedEndpoint()->withoutRelationshipEndpoint(),
        ];
    }

    /**
     * @param iterable<mixed> $members
     *
     * @return list<object>
     */
    private static function members(iterable $members): array
    {
        $objects = [];
        foreach ($members as $member) {
            if (\is_object($member)) {
                $objects[] = $member;
            }
        }

        return $objects;
    }
}
