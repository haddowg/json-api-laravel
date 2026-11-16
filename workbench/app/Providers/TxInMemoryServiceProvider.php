<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\AfterCommit;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Workbench\App\WriteTransaction\PeekTxWidget;
use Workbench\App\WriteTransaction\StampTxWidget;
use Workbench\App\WriteTransaction\SweepTxWidgets;
use Workbench\App\WriteTransaction\TxAcceptingPersister;
use Workbench\App\WriteTransaction\TxListener;
use Workbench\App\WriteTransaction\TxNote;
use Workbench\App\WriteTransaction\TxNoteResource;
use Workbench\App\WriteTransaction\TxTag;
use Workbench\App\WriteTransaction\TxTagResource;
use Workbench\App\WriteTransaction\TxWidget;
use Workbench\App\WriteTransaction\TxWidgetResource;

/**
 * The write-transaction suite on the in-memory witness: widgets and notes over one snapshot
 * coordinator, seeded with note 1, widget 1 holding it, and widget 2.
 */
final class TxInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([TxWidgetResource::class, TxNoteResource::class, TxTagResource::class, StampTxWidget::class, SweepTxWidgets::class, PeekTxWidget::class]);

        /** @var InMemorySnapshotCoordinator $coordinator */
        $coordinator = $this->app->make(InMemorySnapshotCoordinator::class);

        $note = new TxNote('1', 'seeded');
        $notes = new InMemoryDataProvider('txNotes', ['1' => $note], identify: self::identify(), assignId: self::assignId(), coordinator: $coordinator);
        JsonApi::provider($notes);
        JsonApi::persister(new InMemoryDataPersister('txNotes', $notes->store(), static fn(): TxNote => new TxNote()));

        $tags = new InMemoryDataProvider('txTags', [], identify: self::identify(), coordinator: $coordinator);
        JsonApi::provider($tags);
        JsonApi::persister(new InMemoryDataPersister('txTags', $tags->store(), static fn(): TxTag => new TxTag()));

        $widgets = new InMemoryDataProvider(
            'txWidgets',
            ['1' => new TxWidget('1', 'first', [$note]), '2' => new TxWidget('2', 'second')],
            identify: self::identify(),
            assignId: self::assignId(),
            coordinator: $coordinator,
        );
        JsonApi::provider($widgets);
        $resolver = static fn(string $type, string $id): ?object => $type === 'txNotes' ? $notes->store()->find($id) : null;
        JsonApi::persister(new TxAcceptingPersister(new InMemoryDataPersister('txWidgets', $widgets->store(), static fn(): TxWidget => new TxWidget(), $resolver), $this->app->make(AfterCommit::class)));
    }

    public function boot(Dispatcher $events): void
    {
        TxListener::listen($events);
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
