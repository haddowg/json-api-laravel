<?php

declare(strict_types=1);

namespace Workbench\App\Singleton;

use haddowg\JsonApi\Resource\SingletonContext;

/**
 * What the singleton suite's inbox resolves the caller's id from: each user's inbox id, by
 * user id. User 3's inbox is missing, and a guest has none. It records every context the
 * resource resolved from, so a test can count the resolutions and read their attributes.
 */
final class InboxScenario
{
    /**
     * @var array<int|string, string>
     */
    public static array $inboxByUser = [];

    /**
     * @var list<SingletonContext>
     */
    public static array $contexts = [];

    public static function reset(): void
    {
        self::$inboxByUser = ['1' => '1', '2' => '2', '3' => '99'];
        self::$contexts = [];
    }
}
