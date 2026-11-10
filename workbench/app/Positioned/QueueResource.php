<?php

declare(strict_types=1);

namespace Workbench\App\Positioned;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use Workbench\App\Models\LooseMixRow;
use Workbench\App\Models\MixRow as MixRowModel;
use Workbench\App\Models\Queue as QueueModel;

/**
 * A queue keeping its relations in the order its clients send: `songs` by each song's slot,
 * `mix` by a position on link rows declared by their fields, which the data layer writes, and
 * `looseMix` by a position on link rows an `identifiedBy()` closure reads and `fillUsing()`
 * writes. {@see JoinedQueueResource} adds `favourites`, by a join position only a provider
 * querying the join holds. Every position is unique per queue in storage.
 */
final class QueueResource extends AbstractResource
{
    public static string $type = 'queues';

    public function fields(): array
    {
        return [
            Id::make()->allowClientId(),
            Str::make('name'),
            HasMany::make('songs', 'songs')->positionedBy('slot'),
            MorphToMany::make('mix', ['songs'])
                ->storedAs('mixRows')
                ->through(MixRowModel::class)
                ->identifiedByRows(owner: 'queue', type: 'item_type', id: 'song_id')
                ->fields(Integer::make('position'))
                ->positionedBy('position'),
            MorphToMany::make('looseMix', ['songs'])
                ->storedAs('looseMixRows')
                ->identifiedBy(static fn(mixed $queue): array => self::looseMix($queue))
                ->fields(Integer::make('position')->nullable())
                ->positionedBy('position')
                ->fillUsing(static function (mixed $queue, mixed $linkage, array $data): mixed {
                    \assert($linkage instanceof ToManyRelationship);
                    $mode = $data['mode'] ?? Mode::Replace;
                    \assert($mode instanceof Mode);
                    self::fillLooseMix($queue, $linkage, $mode);

                    return $queue;
                }),
        ];
    }

    /**
     * @return list<array{0: string, 1: int, 2: object}>
     */
    private static function looseMix(mixed $queue): array
    {
        $rows = match (true) {
            $queue instanceof Queue => $queue->looseMixRows,
            $queue instanceof QueueModel => $queue->looseMixRows->all(),
            default => [],
        };

        return \array_values(\array_map(static fn(LooseMixRow $row): array => ['songs', $row->song_id, $row], $rows));
    }

    /**
     * Keeps a row per member, deleting the dropped ones and creating the new ones with no
     * position, which the write then gives them, so the column is nullable.
     */
    private static function fillLooseMix(mixed $queue, ToManyRelationship $linkage, Mode $mode): void
    {
        $sent = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            $sent[(int) $identifier->id] = true;
        }

        $kept = [];
        foreach (self::looseMix($queue) as [, $id, $row]) {
            $named = isset($sent[$id]);
            $drop = ($mode === Mode::Replace && !$named) || ($mode === Mode::Remove && $named);
            if ($drop && $row instanceof LooseMixRow && $row->exists) {
                $row->delete();
            }
            if (!$drop) {
                $kept[] = $row;
            }
            unset($sent[$id]);
        }

        $added = $mode === Mode::Remove ? [] : \array_keys($sent);
        if ($queue instanceof Queue) {
            /** @var list<LooseMixRow> $kept */
            $queue->looseMixRows = [...$kept, ...\array_map(static fn(int $id): LooseMixRow => new LooseMixRow(['song_id' => $id]), $added)];
        } elseif ($queue instanceof QueueModel) {
            foreach ($added as $id) {
                $queue->looseMixRows()->create(['song_id' => $id]);
            }
            $queue->unsetRelation('looseMixRows');
        }
    }
}
