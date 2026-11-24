<?php

declare(strict_types=1);

namespace Workbench\App\RequiredRelation;

/**
 * The seed both required-relation wirings serve: two members, and task 1 owned and
 * watched by member 1.
 */
final class Fixtures
{
    /**
     * @return array<int, string>
     */
    public static function members(): array
    {
        return [1 => 'Ada', 2 => 'Grace'];
    }

    /**
     * @return array<int, array{title: string, owner: int, watchers: list<int>}>
     */
    public static function tasks(): array
    {
        return [1 => ['title' => 'Plan', 'owner' => 1, 'watchers' => [1]]];
    }
}
