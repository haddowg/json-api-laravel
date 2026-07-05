<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\EventListener;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\Event\AfterCreateEvent;
use haddowg\JsonApiLaravel\Event\AfterDeleteEvent;
use haddowg\JsonApiLaravel\Event\AfterFetchCollectionEvent;
use haddowg\JsonApiLaravel\Event\AfterFetchOneEvent;
use haddowg\JsonApiLaravel\Event\AfterRelationshipMutateEvent;
use haddowg\JsonApiLaravel\Event\AfterSaveEvent;
use haddowg\JsonApiLaravel\Event\AfterUpdateEvent;
use haddowg\JsonApiLaravel\Event\BeforeCreateEvent;
use haddowg\JsonApiLaravel\Event\BeforeDeleteEvent;
use haddowg\JsonApiLaravel\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiLaravel\Event\BeforeSaveEvent;
use haddowg\JsonApiLaravel\Event\BeforeUpdateEvent;
use haddowg\JsonApiLaravel\Hook\HookContext;
use haddowg\JsonApiLaravel\Hook\ResourceLifecycleHooksInterface;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use haddowg\JsonApiLaravel\Server\TypeMetadataResolver;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The built-in Laravel event subscriber that makes the per-type resource hook
 * methods ({@see ResourceLifecycleHooksInterface}) sugar over the lifecycle events:
 * it listens to every hook-relevant event the
 * {@see \haddowg\JsonApiLaravel\Operation\CrudOperationHandler} fires, resolves the
 * resource for the event's type on the event's server, and — when that resource
 * implements {@see ResourceLifecycleHooksInterface} — calls the matching method,
 * passing the entity plus an assembled {@see HookContext}.
 *
 * It is registered once in the service provider (`Event::subscribe(...)`), so an
 * application has **two** equivalent ways to hook the lifecycle: register its own
 * `Event::listen` on an event class (a cross-cutting concern), or implement the
 * interface on a resource (a per-type concern) — both run from the one dispatch
 * point.
 *
 * It is a no-op for a type whose resource does not implement the interface, and for
 * a bare serializer/hydrator pair (no resource). A before-hook method that throws a
 * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} propagates (aborting
 * the operation); an after-hook method that returns a response replaces the event's
 * response (which the handler reads back). The `ServingEvent`, the
 * `Before*Fetch*`/`BeforeFetchCollection` and the action events are **not** handled
 * here — they are server-level or gate-only seams with no per-type hook method.
 */
final class ResourceHookSubscriber
{
    public function __construct(
        private readonly ServerRegistry $servers,
        private readonly TypeMetadataResolver $types,
    ) {}

    /**
     * The hook-relevant event → handler-method map. Exposed statically so the service
     * provider can register each listener by class-string (a lazy class listener,
     * resolved from the container only when an event fires) rather than constructing
     * the subscriber — and thus its {@see ServerRegistry}/discovery — at boot.
     *
     * @return array<class-string, string>
     */
    public static function eventMap(): array
    {
        return [
            BeforeSaveEvent::class => 'onBeforeSave',
            AfterSaveEvent::class => 'onAfterSave',
            BeforeCreateEvent::class => 'onBeforeCreate',
            AfterCreateEvent::class => 'onAfterCreate',
            BeforeUpdateEvent::class => 'onBeforeUpdate',
            AfterUpdateEvent::class => 'onAfterUpdate',
            BeforeDeleteEvent::class => 'onBeforeDelete',
            AfterDeleteEvent::class => 'onAfterDelete',
            BeforeRelationshipMutateEvent::class => 'onBeforeRelationshipMutate',
            AfterRelationshipMutateEvent::class => 'onAfterRelationshipMutate',
            AfterFetchOneEvent::class => 'onAfterFetchOne',
            AfterFetchCollectionEvent::class => 'onAfterFetchCollection',
        ];
    }

    /**
     * The Laravel event-subscriber contract, delegating to {@see eventMap()} so
     * `Event::subscribe(ResourceHookSubscriber::class)` also works.
     *
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return self::eventMap();
    }

    public function onBeforeSave(BeforeSaveEvent $event): void
    {
        $this->hooks($event->serverName, $event->type)
            ?->beforeSave($event->entity, $event->creating, $this->context($event->serverName, $event->type, $event->request));
    }

    public function onAfterSave(AfterSaveEvent $event): void
    {
        $response = $this->hooks($event->serverName, $event->type)
            ?->afterSave($event->entity, $event->creating, $this->context($event->serverName, $event->type, $event->request));
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    public function onBeforeCreate(BeforeCreateEvent $event): void
    {
        $this->hooks($event->serverName, $event->type)
            ?->beforeCreate($event->entity, $this->context($event->serverName, $event->type, $event->request));
    }

    public function onAfterCreate(AfterCreateEvent $event): void
    {
        $response = $this->hooks($event->serverName, $event->type)
            ?->afterCreate($event->entity, $this->context($event->serverName, $event->type, $event->request));
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    public function onBeforeUpdate(BeforeUpdateEvent $event): void
    {
        $this->hooks($event->serverName, $event->type)
            ?->beforeUpdate($event->entity, $event->original, $this->context($event->serverName, $event->type, $event->request));
    }

    public function onAfterUpdate(AfterUpdateEvent $event): void
    {
        $response = $this->hooks($event->serverName, $event->type)
            ?->afterUpdate($event->entity, $this->context($event->serverName, $event->type, $event->request));
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    public function onBeforeDelete(BeforeDeleteEvent $event): void
    {
        $this->hooks($event->serverName, $event->type)
            ?->beforeDelete($event->entity, $this->context($event->serverName, $event->type, $event->request));
    }

    public function onAfterDelete(AfterDeleteEvent $event): void
    {
        $response = $this->hooks($event->serverName, $event->type)
            ?->afterDelete($event->entity, $this->context($event->serverName, $event->type, $event->request));
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    public function onBeforeRelationshipMutate(BeforeRelationshipMutateEvent $event): void
    {
        $this->hooks($event->serverName, $event->type)
            ?->beforeRelationshipMutate(
                $event->parent,
                $this->relationshipContext($event->serverName, $event->type, $event->request, $event->relation, $event->linkage, $event->mode),
            );
    }

    public function onAfterRelationshipMutate(AfterRelationshipMutateEvent $event): void
    {
        $response = $this->hooks($event->serverName, $event->type)
            ?->afterRelationshipMutate(
                $event->parent,
                $this->relationshipContext($event->serverName, $event->type, $event->request, $event->relation, $event->linkage, $event->mode),
            );
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    public function onAfterFetchOne(AfterFetchOneEvent $event): void
    {
        $response = $this->hooks($event->serverName, $event->type)
            ?->afterFetchOne($event->entity, $this->context($event->serverName, $event->type, $event->request));
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    public function onAfterFetchCollection(AfterFetchCollectionEvent $event): void
    {
        $response = $this->hooks($event->serverName, $event->type)
            ?->afterFetchCollection($event->items, $this->context($event->serverName, $event->type, $event->request));
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    /**
     * The hook-implementing resource for `$type` on `$serverName`, or `null` when
     * the type has no resource (a bare pair) or its resource does not opt into the
     * hook interface.
     */
    private function hooks(string $serverName, string $type): ?ResourceLifecycleHooksInterface
    {
        $server = $this->servers->get($serverName);
        $resource = $this->types->resourceFor($server, $type);

        return $resource instanceof ResourceLifecycleHooksInterface ? $resource : null;
    }

    private function context(string $serverName, string $type, JsonApiRequestInterface $request): HookContext
    {
        return new HookContext($request, $serverName, $type);
    }

    private function relationshipContext(
        string $serverName,
        string $type,
        JsonApiRequestInterface $request,
        RelationInterface $relation,
        object $linkage,
        Mode $mode,
    ): HookContext {
        return new HookContext($request, $serverName, $type, $relation, $linkage, $mode);
    }
}
