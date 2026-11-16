<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the inbox over the store named by `inbox.store`, `eloquent` or `memory`, both seeded
 * from the same rows.
 *
 * | item | folder | subject     | read | archived | links                 |
 * |------|--------|-------------|------|----------|-----------------------|
 * | 1    | 1      | rfp 812     |      |          | rfp 812               |
 * | 2    | 1      | rfp 813     | yes  |          | campaign 1            |
 * | 3    | 1      | campaign 1  |      | yes      | rfp 813, campaign 2   |
 * | 4    | 2      | campaign 2  | yes  | yes      |                       |
 * | 5    | 2      |             |      |          |                       |
 *
 * @internal
 */
final class InboxServiceProvider extends ServiceProvider
{
    public const string AT = '2026-11-01 09:00:00';

    /**
     * @return array<int, string>
     */
    public static function folders(): array
    {
        return [1 => 'Main', 2 => 'Other'];
    }

    /**
     * @return array<int, string>
     */
    public static function rfps(): array
    {
        return [812 => 'Office move', 813 => 'Fleet', 814 => 'Catering'];
    }

    /**
     * @return array<int, string>
     */
    public static function campaigns(): array
    {
        return [1 => 'Spring', 2 => 'Summer'];
    }

    /**
     * @return list<array{id: int, title: string, folder: int, subject_type: ?string, subject_id: ?string, read: bool, archived: bool, links: list<array{string, string}>}>
     */
    public static function items(): array
    {
        return [
            ['id' => 1, 'title' => 'New RFP', 'folder' => 1, 'subject_type' => 'rfp', 'subject_id' => '812', 'read' => false, 'archived' => false, 'links' => [['rfp', '812']]],
            ['id' => 2, 'title' => 'RFP answered', 'folder' => 1, 'subject_type' => 'rfp', 'subject_id' => '813', 'read' => true, 'archived' => false, 'links' => [['campaign', '1']]],
            ['id' => 3, 'title' => 'Campaign live', 'folder' => 1, 'subject_type' => 'campaign', 'subject_id' => '1', 'read' => false, 'archived' => true, 'links' => [['rfp', '813'], ['campaign', '2']]],
            ['id' => 4, 'title' => 'Campaign closed', 'folder' => 2, 'subject_type' => 'campaign', 'subject_id' => '2', 'read' => true, 'archived' => true, 'links' => []],
            ['id' => 5, 'title' => 'Welcome', 'folder' => 2, 'subject_type' => null, 'subject_id' => null, 'read' => false, 'archived' => false, 'links' => []],
        ];
    }

    public function register(): void
    {
        JsonApi::register([RfpResource::class, InboxCampaignResource::class, InboxFolderResource::class, InboxItemResource::class, TallyInboxItems::class]);

        if (config('inbox.store') !== 'memory') {
            Relation::morphMap(['rfp' => Rfp::class, 'campaign' => InboxCampaign::class]);
            JsonApi::register([NativeSubjectItemResource::class]);
            JsonApi::provider(new EloquentDataProvider([
                'inbRfps' => Rfp::class,
                'inbCampaigns' => InboxCampaign::class,
                'inbFolders' => InboxFolder::class,
                'inbItems' => InboxItem::class,
                'inbNativeItems' => InboxItem::class,
            ]), priority: -128);

            return;
        }

        $rfps = [];
        foreach (self::rfps() as $id => $name) {
            $rfps[(string) $id] = new NamedRecord('inbRfps', (string) $id, $name);
        }
        $campaigns = [];
        foreach (self::campaigns() as $id => $name) {
            $campaigns[(string) $id] = new NamedRecord('inbCampaigns', (string) $id, $name);
        }
        $folders = [];
        foreach (self::folders() as $id => $name) {
            $folders[(string) $id] = new InboxFolderRecord((string) $id, $name);
        }
        $at = new \DateTimeImmutable(self::AT);
        $items = [];
        foreach (self::items() as $row) {
            $item = new InboxItemRecord(
                (string) $row['id'],
                $row['title'],
                $row['subject_type'],
                $row['subject_id'],
                $row['read'] ? $at : null,
                $row['archived'] ? $at : null,
                \array_map(static fn(array $link): InboxLinkRecord => new InboxLinkRecord($link[0], $link[1]), $row['links']),
            );
            $folders[(string) $row['folder']]->items[] = $item;
            $items[$item->id] = $item;
        }

        $identify = static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };

        JsonApi::provider(new InMemoryDataProvider('inbRfps', $rfps, identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('inbCampaigns', $campaigns, identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('inbFolders', $folders, identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('inbItems', $items, identify: $identify));
    }
}
