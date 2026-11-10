<?php

declare(strict_types=1);

namespace Workbench\App\Positioned;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use Workbench\App\Models\MixRow as MixRowModel;
use Workbench\App\Models\Queue as QueueModel;

/**
 * A queue keeping three relations in the order its clients send: `songs` by each song's slot,
 * `favourites` by a join position, and `mix` by a position on its own link rows. Each
 * position is unique per queue in storage.
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
            BelongsToMany::make('favourites', 'songs')
                ->fields(Integer::make('position'))
                ->positionedBy('position'),
            MorphToMany::make('mix', ['songs'])
                ->storedAs('mixRows')
                ->identifiedBy(static fn(mixed $queue): array => self::mix($queue))
                ->fields(Integer::make('position')->nullable())
                ->positionedBy('position')
                ->fillUsing(static function (mixed $queue, mixed $linkage, array $data): mixed {
                    \assert($linkage instanceof ToManyRelationship);
                    $mode = $data['mode'] ?? Mode::Replace;
                    \assert($mode instanceof Mode);
                    self::fillMix($queue, $linkage, $mode);

                    return $queue;
                }),
        ];
    }

    /**
     * @return list<array{0: string, 1: int, 2: object}>
     */
    private static function mix(mixed $queue): array
    {
        $rows = match (true) {
            $queue instanceof Queue => $queue->mixRows,
            $queue instanceof QueueModel => $queue->mixRows->all(),
            default => [],
        };

        return \array_values(\array_map(static fn(MixRow|MixRowModel $row): array => ['songs', $row->song_id, $row], $rows));
    }

    /**
     * Keeps a row per member, deleting the dropped ones and creating the new ones with no
     * position, which the write then gives them.
     */
    private static function fillMix(mixed $queue, ToManyRelationship $linkage, Mode $mode): void
    {
        $sent = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            $sent[(int) $identifier->id] = true;
        }

        $kept = [];
        foreach (self::mix($queue) as [, $id, $row]) {
            $named = isset($sent[$id]);
            $drop = ($mode === Mode::Replace && !$named) || ($mode === Mode::Remove && $named);
            if ($drop && $row instanceof MixRowModel) {
                $row->delete();
            }
            if (!$drop) {
                $kept[] = $row;
            }
            unset($sent[$id]);
        }

        $added = $mode === Mode::Remove ? [] : \array_keys($sent);
        if ($queue instanceof Queue) {
            /** @var list<MixRow> $kept */
            $queue->mixRows = [...$kept, ...\array_map(static fn(int $id): MixRow => new MixRow($id), $added)];
        } elseif ($queue instanceof QueueModel) {
            foreach ($added as $id) {
                $queue->mixRows()->create(['song_id' => $id]);
            }
            $queue->unsetRelation('mixRows');
        }
    }
}
