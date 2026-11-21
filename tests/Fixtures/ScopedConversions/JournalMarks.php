<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedConversions;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\Mode;

/**
 * The link rows behind every journal's `marks`, outside either data layer, so a test reads
 * the values a write left on them.
 *
 * @internal
 */
final class JournalMarks
{
    /**
     * @var list<JournalMark>
     */
    public static array $rows = [];

    /**
     * @return list<array{0: string, 1: string, 2: JournalMark}>
     */
    public static function of(mixed $journal): array
    {
        $id = self::idOf($journal);

        return \array_values(\array_map(
            static fn(JournalMark $row): array => [$row->type, $row->id, $row],
            \array_filter(self::$rows, static fn(JournalMark $row): bool => $row->journal === $id),
        ));
    }

    /**
     * Keeps a row per member the linkage names, dropping or adding rows for the mode.
     */
    public static function fill(mixed $journal, ToManyRelationship $linkage, Mode $mode): void
    {
        $id = self::idOf($journal);
        if ($id === null) {
            return;
        }

        $named = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            $named[$identifier->type . ':' . $identifier->id] = [$identifier->type, (string) $identifier->id];
        }

        $kept = [];
        foreach (self::$rows as $row) {
            $key = $row->type . ':' . $row->id;
            $isNamed = $row->journal === $id && isset($named[$key]);
            $drop = $row->journal === $id && (($mode === Mode::Replace && !$isNamed) || ($mode === Mode::Remove && $isNamed));
            if (!$drop) {
                $kept[] = $row;
            }
            if ($row->journal === $id) {
                unset($named[$key]);
            }
        }

        foreach ($mode === Mode::Remove ? [] : $named as [$type, $member]) {
            $kept[] = new JournalMark($id, $type, $member);
        }
        self::$rows = $kept;
    }

    private static function idOf(mixed $journal): ?string
    {
        $id = Accessor::get($journal, 'id');

        return \is_int($id) || \is_string($id) ? (string) $id : null;
    }
}
