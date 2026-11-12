<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\LinkRows\Board;
use Workbench\App\LinkRows\BoardResource;
use Workbench\App\LinkRows\Fixtures;
use Workbench\App\LinkRows\Note;
use Workbench\App\LinkRows\NoteResource;
use Workbench\App\LinkRows\Photo;
use Workbench\App\LinkRows\PhotoResource;
use Workbench\App\LinkRows\Pin;
use Workbench\App\Models\BoardItem;

/**
 * The in-memory half of the link row conformance wiring, seeded from {@see Fixtures}. A board's
 * link rows are unsaved instances of the `through()` model, which core reads and writes in
 * memory without touching a database.
 */
final class LinkRowsInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([BoardResource::class, NoteResource::class, PhotoResource::class]);

        $notes = [];
        foreach (Fixtures::notes() as $id => $title) {
            $notes[(string) $id] = new Note((string) $id, $title);
        }
        $photos = [];
        foreach (Fixtures::photos() as $id => $caption) {
            $photos[(string) $id] = new Photo((string) $id, $caption);
        }
        $boards = [];
        foreach (Fixtures::boards() as $id => $row) {
            $boards[(string) $id] = new Board(
                (string) $id,
                $row['name'],
                \array_map(static fn(array $item): BoardItem => new BoardItem(['item_type' => $item['type'], 'item_id' => $item['id'], 'position' => $item['position'], 'label' => $item['label']]), $row['items']),
                \array_values(\array_map(static fn(int $noteId, int $rank): Pin => new Pin($noteId, $rank), \array_keys($row['pins']), $row['pins'])),
            );
            foreach (\array_keys($row['pins']) as $noteId) {
                $notes[(string) $noteId]->pinnedOn[] = $boards[(string) $id];
            }
        }

        /** @var InMemorySnapshotCoordinator $coordinator */
        $coordinator = $this->app->make(InMemorySnapshotCoordinator::class);
        $identify = static fn(object $row): string => \property_exists($row, 'id') && \is_string($row->id) ? $row->id : '';

        $boardProvider = new InMemoryDataProvider('boards', $boards, identify: $identify, coordinator: $coordinator);
        JsonApi::provider($boardProvider);
        JsonApi::provider(new InMemoryDataProvider('notes', $notes, identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('photos', $photos, identify: $identify));
        JsonApi::persister(new InMemoryDataPersister('boards', $boardProvider->store(), static fn(): Board => new Board()));
    }
}
