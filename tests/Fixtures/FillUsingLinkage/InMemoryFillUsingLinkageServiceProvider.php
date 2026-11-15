<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsingLinkage;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * The in-memory witness of {@see EloquentFillUsingLinkageServiceProvider}, seeded with the same
 * rows.
 *
 * @internal
 */
final class InMemoryFillUsingLinkageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([MemoResource::class, StickyResource::class, LabelResource::class, TemplateSettingResource::class]);

        /** @var InMemorySnapshotCoordinator $coordinator */
        $coordinator = $this->app->make(InMemorySnapshotCoordinator::class);
        $identify = static fn(object $row): string => \property_exists($row, 'id') && \is_string($row->id) ? $row->id : '';
        $assignId = static function (object $row, string $id): void {
            \assert($row instanceof MemoRecord);
            $row->id = $id;
        };

        $memos = new InMemoryDataProvider('memos', ['1' => new MemoRecord('1', 'Todo', '1', ['1'], '1', ['1'])], identify: $identify, assignId: $assignId, coordinator: $coordinator);
        JsonApi::provider(new TemplateSettingsProvider());
        JsonApi::provider($memos);
        JsonApi::provider(new InMemoryDataProvider('labels', ['1' => new LabelRecord('1', 'urgent'), '2' => new LabelRecord('2', 'ux')], identify: $identify));
        $stickies = new InMemoryDataProvider('stickies', [], identify: $identify, assignId: $assignId, coordinator: $coordinator);
        JsonApi::provider($stickies);
        JsonApi::persister(new InMemoryDataPersister('memos', $memos->store(), static fn(): MemoRecord => new MemoRecord()));
        JsonApi::persister(new InMemoryDataPersister('stickies', $stickies->store(), static fn(): MemoRecord => new MemoRecord()));
    }
}
