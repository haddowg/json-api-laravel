<?php

declare(strict_types=1);

namespace Workbench\App\GateOrder;

/**
 * What ran for `gateWidgets`: each resource hook method, each event a listener saw, and each
 * action handler, in order.
 */
final class GateLog
{
    /** @var list<string> */
    public static array $entries = [];

    public static function reset(): void
    {
        self::$entries = [];
    }
}
