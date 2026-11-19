<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\Event\AfterCreateEvent;
use haddowg\JsonApiLaravel\Event\BeforeCreateEvent;
use haddowg\JsonApiLaravel\Event\BeforeDeleteEvent;
use haddowg\JsonApiLaravel\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiLaravel\Event\BeforeUpdateEvent;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The typed request meta suite, on Eloquent or the in-memory witness by
 * `typed_request_meta.store`. Note 1 "Plan" holds item 1 and leads with it, note 2
 * "Broken" holds none, and item 2 is free. `typed_request_meta.misdeclared` registers one
 * misdeclared type or action instead, without its routes, so a test can register them.
 */
final class TypedRequestMetaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $misdeclared = config('typed_request_meta.misdeclared');
        if (\is_string($misdeclared)) {
            JsonApi::register([TrPlainResource::class, match ($misdeclared) {
                'getmeta' => TrGetMetaResource::class,
                'twice' => TrTwiceResource::class,
                'entries' => TrTwoEntriesResource::class,
                default => RawUploadNote::class,
            }])->ignoreRoutes();
            $this->registerInMemory();

            return;
        }

        JsonApi::register([
            TrNoteResource::class, TrItemResource::class, TrPlainResource::class, TrExportResource::class,
            TrJobSerializer::class, TrLabelSerializer::class, ArchiveNote::class, ImportItem::class, QueueNote::class,
        ]);
        JsonApi::persister(new TrExportsPersister());
        JsonApi::provider(new InMemoryDataProvider('tr-labels', ['urgent' => new TrLabel('urgent', 'Urgent')], identify: static fn(object $label): string => $label instanceof TrLabel ? $label->id : ''));

        if (config('typed_request_meta.store') === 'memory') {
            $this->registerInMemory();

            return;
        }

        // The open pivot is written to a join row, which only Eloquent keeps.
        JsonApi::register([TrShelfResource::class]);
        $models = ['tr-notes' => Note::class, 'tr-items' => Item::class, 'tr-plains' => Plain::class, 'tr-shelves' => Shelf::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }

    public function boot(): void
    {
        foreach ([BeforeCreateEvent::class, AfterCreateEvent::class, BeforeUpdateEvent::class, BeforeDeleteEvent::class, BeforeRelationshipMutateEvent::class] as $event) {
            Event::listen($event, [TrMetaRecorder::class, 'handle']);
        }
    }

    private function registerInMemory(): void
    {
        /** @var InMemorySnapshotCoordinator $coordinator */
        $coordinator = $this->app->make(InMemorySnapshotCoordinator::class);

        $items = ['1' => new ItemRecord('1', 'Pen', ['size' => 3]), '2' => new ItemRecord('2', 'Ink')];
        $notes = [
            '1' => new NoteRecord('1', 'Plan', items: [$items['1']], lead: $items['1']),
            '2' => new NoteRecord('2', 'Broken'),
        ];
        $plains = ['1' => new PlainRecord('1', 'Plain')];

        $identify = static function (object $row): string {
            $id = Accessor::get($row, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
        $assignId = static function (object $row, string $id): void {
            Accessor::set($row, 'id', $id);
        };

        $noteProvider = new InMemoryDataProvider('tr-notes', $notes, identify: $identify, assignId: $assignId, coordinator: $coordinator);
        $itemProvider = new InMemoryDataProvider('tr-items', $items, identify: $identify, assignId: $assignId, coordinator: $coordinator);
        $plainProvider = new InMemoryDataProvider('tr-plains', $plains, identify: $identify, assignId: $assignId, coordinator: $coordinator);
        JsonApi::provider($noteProvider);
        JsonApi::provider($itemProvider);
        JsonApi::provider($plainProvider);

        $resolve = static fn(string $type, string $id): ?object => match ($type) {
            'tr-notes' => $noteProvider->store()->find($id),
            'tr-items' => $itemProvider->store()->find($id),
            default => null,
        };
        JsonApi::persister(new InMemoryDataPersister('tr-notes', $noteProvider->store(), static fn(): NoteRecord => new NoteRecord(), $resolve));
        JsonApi::persister(new InMemoryDataPersister('tr-items', $itemProvider->store(), static fn(): ItemRecord => new ItemRecord(), $resolve));
        JsonApi::persister(new InMemoryDataPersister('tr-plains', $plainProvider->store(), static fn(): PlainRecord => new PlainRecord(), $resolve));
    }
}
