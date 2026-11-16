<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Singleton\ArchiveInbox;
use Workbench\App\Singleton\Inbox;
use Workbench\App\Singleton\InboxItem;
use Workbench\App\Singleton\InboxItemResource;
use Workbench\App\Singleton\InboxLabel;
use Workbench\App\Singleton\InboxLabelResource;
use Workbench\App\Singleton\InboxResource;

/**
 * The singleton suite on the in-memory witness: Ada's inbox 1 holding items 1 and 2 and
 * labelled urgent, Bob's inbox 2 holding item 3, and the labels urgent and later.
 */
final class SingletonInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([InboxResource::class, InboxItemResource::class, InboxLabelResource::class, ArchiveInbox::class]);

        /** @var InMemorySnapshotCoordinator $coordinator */
        $coordinator = $this->app->make(InMemorySnapshotCoordinator::class);

        $urgent = new InboxLabel('1', 'urgent');
        $later = new InboxLabel('2', 'later');
        $ada = new Inbox('1', 1, 'Ada inbox', labels: [$urgent]);
        $bob = new Inbox('2', 2, 'Bob inbox');
        $items = [
            '1' => new InboxItem('1', 'hello', $ada),
            '2' => new InboxItem('2', 'invoice', $ada),
            '3' => new InboxItem('3', 'lunch', $bob),
        ];
        $ada->items = [$items['1'], $items['2']];
        $bob->items = [$items['3']];

        $labels = new InMemoryDataProvider('inboxLabels', ['1' => $urgent, '2' => $later], identify: self::identify(), assignId: self::assignId(), coordinator: $coordinator);
        JsonApi::provider($labels);
        JsonApi::persister(new InMemoryDataPersister('inboxLabels', $labels->store(), static fn(): InboxLabel => new InboxLabel()));

        $inboxItems = new InMemoryDataProvider('inboxItems', $items, identify: self::identify(), assignId: self::assignId(), coordinator: $coordinator);
        JsonApi::provider($inboxItems);

        $inboxes = new InMemoryDataProvider('inboxes', ['1' => $ada, '2' => $bob], identify: self::identify(), assignId: self::assignId(), coordinator: $coordinator);
        JsonApi::provider($inboxes);

        $resolver = static fn(string $type, string $id): ?object => match ($type) {
            'inboxLabels' => $labels->store()->find($id),
            'inboxItems' => $inboxItems->store()->find($id),
            'inboxes' => $inboxes->store()->find($id),
            default => null,
        };
        JsonApi::persister(new InMemoryDataPersister('inboxItems', $inboxItems->store(), static fn(): InboxItem => new InboxItem(), $resolver));
        JsonApi::persister(new InMemoryDataPersister('inboxes', $inboxes->store(), static fn(): Inbox => new Inbox(), $resolver));
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
