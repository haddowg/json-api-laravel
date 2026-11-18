<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use Workbench\App\ForeignKey\Models\FkItem;
use Workbench\App\ForeignKey\Models\FkMember;
use Workbench\App\ForeignKey\Models\FkNote;
use Workbench\App\ForeignKey\Models\FkProfile;
use Workbench\App\ForeignKey\Models\FkSecret;
use Workbench\App\ForeignKey\Models\FkTeam;

/**
 * The shared seed. Ada owns items 1 to 5 (One to Five), Bob item 6 and Cy none, and item 7
 * has no owner. Ada has notes 1 and 2, the second archived, and Bob note 3. Ada has profile
 * 1, Bob none, and profile 2 has no owner. Ada has secrets 1 and 2, the second hidden. Team 1
 * has members 1 to 3, team 2 member 4.
 */
final class ForeignKeySeed
{
    /**
     * @return array{items: list<array{id: int, title: string, owner_key: ?string}>, notes: list<array{id: int, body: string, owner: string, archived: bool}>, profiles: list<array{id: int, bio: string, owner: ?string}>, secrets: list<array{id: int, label: string, owner: ?string, hidden: bool}>, teams: list<array{id: int, name: string}>, members: list<array{id: int, name: string, team_id: ?int}>}
     */
    public static function rows(): array
    {
        $items = [];
        foreach ([1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five'] as $id => $title) {
            $items[] = ['id' => $id, 'title' => $title, 'owner_key' => 'ada'];
        }
        $items[] = ['id' => 6, 'title' => 'Six', 'owner_key' => 'bob'];
        $items[] = ['id' => 7, 'title' => 'Seven', 'owner_key' => null];

        return [
            'items' => $items,
            'notes' => [
                ['id' => 1, 'body' => 'Hello', 'owner' => 'ada', 'archived' => false],
                ['id' => 2, 'body' => 'Old', 'owner' => 'ada', 'archived' => true],
                ['id' => 3, 'body' => 'Hi', 'owner' => 'bob', 'archived' => false],
            ],
            'profiles' => [
                ['id' => 1, 'bio' => 'Mathematician', 'owner' => 'ada'],
                ['id' => 2, 'bio' => 'Poet', 'owner' => null],
            ],
            'secrets' => [
                ['id' => 1, 'label' => 'Shown', 'owner' => 'ada', 'hidden' => false],
                ['id' => 2, 'label' => 'Hidden', 'owner' => 'ada', 'hidden' => true],
            ],
            'teams' => [['id' => 1, 'name' => 'Red'], ['id' => 2, 'name' => 'Blue']],
            'members' => [
                ['id' => 1, 'name' => 'Ann', 'team_id' => 1],
                ['id' => 2, 'name' => 'Ben', 'team_id' => 1],
                ['id' => 3, 'name' => 'Cat', 'team_id' => 1],
                ['id' => 4, 'name' => 'Dan', 'team_id' => 2],
            ],
        ];
    }

    /**
     * Seeds the rows into the Eloquent tables.
     */
    public static function seedEloquent(): void
    {
        $rows = self::rows();
        foreach ($rows['items'] as $row) {
            FkItem::query()->create($row);
        }
        foreach ($rows['notes'] as $row) {
            FkNote::query()->create($row);
        }
        foreach ($rows['profiles'] as $row) {
            FkProfile::query()->create($row);
        }
        foreach ($rows['secrets'] as $row) {
            FkSecret::query()->create($row);
        }
        foreach ($rows['teams'] as $row) {
            FkTeam::query()->create($row);
        }
        foreach ($rows['members'] as $row) {
            FkMember::query()->create($row);
        }
    }
}
