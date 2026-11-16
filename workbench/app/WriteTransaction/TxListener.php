<?php

declare(strict_types=1);

namespace Workbench\App\WriteTransaction;

use haddowg\JsonApi\Exception\AccessDenied;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\AfterCommit;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\Event\AfterActionEvent;
use haddowg\JsonApiLaravel\Event\AfterCreateEvent;
use haddowg\JsonApiLaravel\Event\AfterDeleteEvent;
use haddowg\JsonApiLaravel\Event\AfterRelationshipMutateEvent;
use haddowg\JsonApiLaravel\Event\AfterSaveEvent;
use haddowg\JsonApiLaravel\Event\AfterUpdateEvent;
use haddowg\JsonApiLaravel\Event\BeforeActionEvent;
use haddowg\JsonApiLaravel\Event\BeforeCreateEvent;
use haddowg\JsonApiLaravel\Event\BeforeDeleteEvent;
use haddowg\JsonApiLaravel\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiLaravel\Event\BeforeSaveEvent;
use haddowg\JsonApiLaravel\Event\BeforeUpdateEvent;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Acts out {@see TxScenario} on the `txWidgets` lifecycle events.
 */
final readonly class TxListener
{
    public const array EVENTS = [
        BeforeSaveEvent::class,
        BeforeCreateEvent::class,
        BeforeUpdateEvent::class,
        BeforeDeleteEvent::class,
        BeforeRelationshipMutateEvent::class,
        BeforeActionEvent::class,
        AfterActionEvent::class,
        AfterCreateEvent::class,
        AfterUpdateEvent::class,
        AfterSaveEvent::class,
        AfterDeleteEvent::class,
        AfterRelationshipMutateEvent::class,
    ];

    public function __construct(private TxSideWriter $writer, private AfterCommit $afterCommit, private DataPersisterRegistry $persisters) {}

    public static function listen(Dispatcher $events): void
    {
        foreach (self::EVENTS as $event) {
            $events->listen($event, static function (object $fired): void {
                app(self::class)->on($fired);
            });
        }
    }

    public function on(object $event): void
    {
        if ($event instanceof BeforeCreateEvent && TxScenario::$saveOnCreate && \in_array($event->type, ['txWidgets', 'txTags'], true)) {
            // As an application does to read a generated key before the write finishes.
            $this->persisters->forType($event->type)->create($event->type, $event->entity);
            if (TxScenario::$throwAfterSave) {
                throw new AccessDenied('Refused by the test listener after it saved.');
            }
        }

        if ((\property_exists($event, 'type') ? $event->{'type'} : null) !== 'txWidgets') {
            return;
        }

        $class = $event::class;
        $name = (new \ReflectionClass($event))->getShortName();
        if (\str_starts_with($name, 'After')) {
            TxScenario::$log[] = $name;
        }
        if (TxScenario::$afterCommitOn === $class) {
            TxScenario::registerCallback($this->afterCommit->register(...), 'callback from ' . $name);
        }
        if (TxScenario::$nestOn === $class) {
            $this->writer->begin();
        }
        if (TxScenario::$sideWriteOn === $class) {
            $this->writer->write('by ' . $name);
        }
        if (TxScenario::$unnestOn === $class) {
            $this->writer->commit();
        }
        if (TxScenario::$throwOn === $class && $this->targets($event)) {
            throw new AccessDenied('Refused by the test listener.');
        }
    }

    private function targets(object $event): bool
    {
        if (TxScenario::$throwForName === null) {
            return true;
        }

        $entity = \property_exists($event, 'entity') ? $event->{'entity'} : null;

        return \is_object($entity) && Accessor::get($entity, 'name') === TxScenario::$throwForName;
    }
}
