<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\Event\AfterFetchCollectionEvent;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Workbench\App\TypedMeta\PinCard;
use Workbench\App\TypedMeta\TmBoard;
use Workbench\App\TypedMeta\TmBoardResource;
use Workbench\App\TypedMeta\TmCard;
use Workbench\App\TypedMeta\TmCardResource;
use Workbench\App\TypedMeta\TmLabel;
use Workbench\App\TypedMeta\TmLabelSerializer;
use Workbench\App\TypedMeta\TmMetaListener;

/**
 * The typed-document-meta suite on the in-memory witness: board 1 holds cards 1 to 3, card
 * 2 done and card 3 pinned, beside the labels urgent and later.
 */
final class TypedMetaInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([TmCardResource::class, TmBoardResource::class, TmLabelSerializer::class, PinCard::class]);

        /** @var InMemorySnapshotCoordinator $coordinator */
        $coordinator = $this->app->make(InMemorySnapshotCoordinator::class);

        $board = new TmBoard('1', 'Sprint');
        $cards = [
            '1' => new TmCard('1', 'Plan', false, $board),
            '2' => new TmCard('2', 'Build', true, $board),
            '3' => new TmCard('3', 'Ship', false, $board),
        ];
        $board->cards = \array_values($cards);
        $board->pinned = $cards['3'];

        $cardProvider = new InMemoryDataProvider('tm-cards', $cards, identify: self::identify(), assignId: self::assignId(), coordinator: $coordinator);
        $boards = new InMemoryDataProvider('tm-boards', ['1' => $board], identify: self::identify(), assignId: self::assignId(), coordinator: $coordinator);
        JsonApi::provider($cardProvider);
        JsonApi::provider($boards);

        $resolver = static fn(string $type, string $id): ?object => match ($type) {
            'tm-cards' => $cardProvider->store()->find($id),
            'tm-boards' => $boards->store()->find($id),
            default => null,
        };
        JsonApi::persister(new InMemoryDataPersister('tm-cards', $cardProvider->store(), static fn(): TmCard => new TmCard(), $resolver));

        JsonApi::provider(new InMemoryDataProvider('tm-labels', ['urgent' => new TmLabel('urgent', 'Urgent'), 'later' => new TmLabel('later', 'Later')], identify: self::identify()));
    }

    public function boot(): void
    {
        Event::listen(AfterFetchCollectionEvent::class, [TmMetaListener::class, 'handle']);
    }

    private static function identify(): \Closure
    {
        return static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
    }

    private static function assignId(): \Closure
    {
        return static function (object $item, string $id): void {
            Accessor::set($item, 'id', $id);
        };
    }
}
