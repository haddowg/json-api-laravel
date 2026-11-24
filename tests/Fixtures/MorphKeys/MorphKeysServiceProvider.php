<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires notes over the store named by `morph_keys.store`, `eloquent` or `memory`, both
 * seeded from the same rows. Counters 2, badges 3 and 4, and code beta are hidden. Each stored id is
 * the target's storage key or its wire id:
 *
 * | note | subject       | pins          |
 * |------|---------------|---------------|
 * | 1    | mkCounters 1  | mkBadges b-2  |
 * | 2    | mkCounters 2  | mkBadges 3    |
 * | 3    | mkBadges 1    | mkCounters 1  |
 * | 4    | mkBadges b-2  | mkBadges 1    |
 * | 5    | mkBadges b-3  |               |
 * | 6    | mkBadges 4    |               |
 * | 7    | mkTokens 1    | mkTokens t-2  |
 * | 8    | mkTokens t-2  |               |
 * | 9    |               |               |
 * | 10   | mkCodes alpha |               |
 * | 11   | mkCodes beta  |               |
 *
 * @internal
 */
final class MorphKeysServiceProvider extends ServiceProvider
{
    /**
     * @return list<array{id: int, subject_type: ?string, subject_id: ?string, pins: list<array{string, string}>}>
     */
    public static function notes(): array
    {
        return [
            ['id' => 1, 'subject_type' => 'mkCounters', 'subject_id' => '1', 'pins' => [['mkBadges', 'b-2']]],
            ['id' => 2, 'subject_type' => 'mkCounters', 'subject_id' => '2', 'pins' => [['mkBadges', '3']]],
            ['id' => 3, 'subject_type' => 'mkBadges', 'subject_id' => '1', 'pins' => [['mkCounters', '1']]],
            ['id' => 4, 'subject_type' => 'mkBadges', 'subject_id' => 'b-2', 'pins' => [['mkBadges', '1']]],
            ['id' => 5, 'subject_type' => 'mkBadges', 'subject_id' => 'b-3', 'pins' => []],
            ['id' => 6, 'subject_type' => 'mkBadges', 'subject_id' => '4', 'pins' => []],
            ['id' => 7, 'subject_type' => 'mkTokens', 'subject_id' => '1', 'pins' => [['mkTokens', 't-2']]],
            ['id' => 8, 'subject_type' => 'mkTokens', 'subject_id' => 't-2', 'pins' => []],
            ['id' => 9, 'subject_type' => null, 'subject_id' => null, 'pins' => []],
            ['id' => 10, 'subject_type' => 'mkCodes', 'subject_id' => 'alpha', 'pins' => []],
            ['id' => 11, 'subject_type' => 'mkCodes', 'subject_id' => 'beta', 'pins' => []],
        ];
    }

    /**
     * Each target type's rows as `[id, name, hidden]`, and the prefix its wire id carries.
     *
     * @return array<string, array{table: string, prefix: string, rows: list<array{int, string, bool}>}>
     */
    public static function targets(): array
    {
        return [
            'mkCounters' => ['table' => 'mk_counters', 'prefix' => '', 'rows' => [[1, 'one', false], [2, 'two', true]]],
            'mkBadges' => ['table' => 'mk_badges', 'prefix' => 'b-', 'rows' => [[1, 'gold', false], [2, 'silver', false], [3, 'bronze', true], [4, 'tin', true]]],
            'mkTokens' => ['table' => 'mk_tokens', 'prefix' => 't-', 'rows' => [[1, 'red', false], [2, 'blue', false]]],
        ];
    }

    /**
     * The codes as `[id, name, hidden]`.
     *
     * @return list<array{string, string, bool}>
     */
    public static function codes(): array
    {
        return [['alpha', 'Alpha', false], ['beta', 'Beta', true]];
    }

    public function register(): void
    {
        JsonApi::register([MkNoteResource::class, MkCounterResource::class, MkBadgeResource::class, MkTokenResource::class, MkCodeResource::class]);

        if (config('morph_keys.store') !== 'memory') {
            JsonApi::provider(new EloquentDataProvider([
                'mkNotes' => MkNote::class,
                'mkCounters' => MkCounter::class,
                'mkBadges' => MkBadge::class,
                'mkTokens' => MkToken::class,
                'mkCodes' => MkCode::class,
            ]), priority: -128);

            return;
        }

        $notes = [];
        foreach (self::notes() as $row) {
            $notes[(string) $row['id']] = new MkNoteRecord(
                (string) $row['id'],
                $row['subject_type'],
                $row['subject_id'],
                \array_map(static fn(array $pin): MkPinRecord => new MkPinRecord($pin[0], $pin[1]), $row['pins']),
            );
        }
        JsonApi::provider(new InMemoryDataProvider('mkNotes', $notes, identify: static function (object $note): string {
            $id = Accessor::get($note, 'id');

            return \is_scalar($id) ? (string) $id : '';
        }));

        foreach (self::targets() as $type => $target) {
            $visible = [];
            foreach ($target['rows'] as [$id, $name, $hidden]) {
                if (!$hidden) {
                    $visible[$target['prefix'] . $id] = new MkTargetRecord($type, $id, $name);
                }
            }
            JsonApi::provider($type === 'mkTokens'
                ? new InMemoryDataProvider($type, $visible, identify: static fn(object $row): string => $row instanceof MkTargetRecord ? $target['prefix'] . $row->id : '')
                : new VisibleTargetsProvider($type, $visible));
        }

        $codes = [];
        foreach (self::codes() as [$id, $name, $hidden]) {
            if (!$hidden) {
                $codes[$id] = new MkCodeRecord($id, $name);
            }
        }
        JsonApi::provider(new VisibleTargetsProvider('mkCodes', $codes));
    }
}
