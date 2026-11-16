<?php

declare(strict_types=1);

namespace Workbench\App\GateOrder;

use haddowg\JsonApiLaravel\Event\AfterFetchOneEvent;
use haddowg\JsonApiLaravel\Event\BeforeActionEvent;
use haddowg\JsonApiLaravel\Event\BeforeCreateEvent;
use haddowg\JsonApiLaravel\Event\BeforeDeleteEvent;
use haddowg\JsonApiLaravel\Event\BeforeFetchCollectionEvent;
use haddowg\JsonApiLaravel\Event\BeforeFetchRelatedEvent;
use haddowg\JsonApiLaravel\Event\BeforeFetchRelationshipEvent;
use haddowg\JsonApiLaravel\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiLaravel\Event\BeforeSaveEvent;
use haddowg\JsonApiLaravel\Event\BeforeUpdateEvent;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * A listener on every Before* event and AfterFetchOne, recording each `gateWidgets` event it
 * sees.
 */
final class GateListener
{
    public const array EVENTS = [
        BeforeSaveEvent::class,
        BeforeCreateEvent::class,
        BeforeUpdateEvent::class,
        BeforeDeleteEvent::class,
        BeforeRelationshipMutateEvent::class,
        AfterFetchOneEvent::class,
        BeforeFetchCollectionEvent::class,
        BeforeFetchRelatedEvent::class,
        BeforeFetchRelationshipEvent::class,
        BeforeActionEvent::class,
    ];

    public static function listen(Dispatcher $events): void
    {
        foreach (self::EVENTS as $event) {
            $events->listen($event, static function (object $fired): void {
                if (\property_exists($fired, 'type') && $fired->{'type'} === 'gateWidgets') {
                    GateLog::$entries[] = (new \ReflectionClass($fired))->getShortName();
                }
            });
        }
    }
}
