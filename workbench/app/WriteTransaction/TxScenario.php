<?php

declare(strict_types=1);

namespace Workbench\App\WriteTransaction;

use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use Illuminate\Support\Facades\DB;

/**
 * What the test listeners and actions do on the next request: which event writes a note on
 * the side, which one throws, and which ones open and commit a transaction of the listener's
 * own.
 */
final class TxScenario
{
    /** @var class-string|null */
    public static ?string $sideWriteOn = null;

    /** @var class-string|null */
    public static ?string $throwOn = null;

    /** The widget name a throw is limited to, or null for any widget. */
    public static ?string $throwForName = null;

    /** @var class-string|null */
    public static ?string $nestOn = null;

    /** @var class-string|null */
    public static ?string $unnestOn = null;

    /** Whether the `txWidgets` persister accepts a create or update for asynchronous processing. */
    public static bool $accept = false;

    /** Whether the actions' handlers throw once they have written. */
    public static bool $actionThrows = false;

    /** The connection's transaction level the last side write ran at, on Eloquent. */
    public static ?int $levelSeen = null;

    /** @var class-string|null the event whose listener registers an after-commit callback */
    public static ?string $afterCommitOn = null;

    /** Whether the actions register an after-commit callback once they have written. */
    public static bool $actionAfterCommit = false;

    /** Whether the actions return an error response instead of throwing. */
    public static bool $actionReturnsError = false;

    /** Whether the `txWidgets` persister registers an after-commit callback when it accepts a write. */
    public static bool $queueOnAccept = false;

    /** Whether each after-commit callback is preceded by one that throws. */
    public static bool $callbackThrows = false;

    /**
     * What ran, in order: each after-commit callback and each After* event on `txWidgets`.
     *
     * @var list<string>
     */
    public static array $log = [];

    /**
     * The connection's transaction level each after-commit callback ran at, on Eloquent.
     *
     * @var list<int>
     */
    public static array $callbackLevels = [];

    public static function reset(): void
    {
        self::$sideWriteOn = null;
        self::$throwOn = null;
        self::$throwForName = null;
        self::$nestOn = null;
        self::$unnestOn = null;
        self::$accept = false;
        self::$actionThrows = false;
        self::$levelSeen = null;
        self::$afterCommitOn = null;
        self::$actionAfterCommit = false;
        self::$actionReturnsError = false;
        self::$queueOnAccept = false;
        self::$callbackThrows = false;
        self::$log = [];
        self::$callbackLevels = [];
    }

    /**
     * An after-commit callback that logs `$label`, preceded by one that throws when
     * {@see $callbackThrows} is set.
     *
     * @param callable(callable(): mixed): void $register
     */
    public static function registerCallback(callable $register, string $label): void
    {
        if (self::$callbackThrows) {
            $register(static function (): never {
                throw new \RuntimeException('Thrown by the test callback.');
            });
        }

        $register(static function () use ($label): void {
            self::$log[] = $label;
            if (app(DataPersisterRegistry::class)->forType('txNotes') instanceof EloquentDataPersister) {
                self::$callbackLevels[] = DB::connection()->transactionLevel();
            }
        });
    }
}
