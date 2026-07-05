<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Listeners;

use haddowg\JsonApiLaravel\Event\AfterDeleteEvent;
use haddowg\JsonApiLaravel\Event\AfterSaveEvent;
use haddowg\JsonApiLaravel\Event\ServingEvent;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Events\Dispatcher;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Workbench\App\MusicCatalog\Support\AuditLog;

/**
 * The **cross-cutting event subscriber** — the workbench's witness for the lifecycle
 * seam's *event* mechanism (the twin of the per-type resource-method hooks on
 * {@see \Workbench\App\MusicCatalog\JsonApi\PlaylistResource}), ported from the Symfony
 * example's `AuditLogSubscriber`. Being a plain Laravel event subscriber registered via
 * `Event::subscribe()` in both wiring providers, it listens to events fired for **every**
 * type, so one concern (here: an audit trail + a read-only gate) spans the whole API
 * without touching any resource.
 *
 * Two hooks:
 *  - a **`serving`** gate (fired once per request, before the operation): when the request
 *    carries an `X-Read-Only: on` header it aborts with a `403`, so a deploy flag can
 *    freeze every write across the API in one place. A `serving` throw aborts before the
 *    operation runs — no entity is loaded, nothing commits;
 *  - an **after-commit** audit record on {@see AfterSaveEvent} (every create AND update —
 *    `$creating` distinguishes them) and {@see AfterDeleteEvent}, appended to the
 *    singleton {@see AuditLog}. After events fire post-commit, so an entry means the
 *    write durably happened. (The bundle's subscriber captures the wire id in a
 *    `BeforeDelete` hook because Doctrine erases the identifier after the flush; an
 *    Eloquent model and an in-memory POPO keep theirs, so the id reads directly off the
 *    deleted entity here.)
 *
 * The {@see ServerRegistry} resolves the type's serializer — on the server the operation
 * dispatched on — to read the committed entity's wire id for the audit line, so an
 * admin-server-only type (`users`) audits correctly under multi-server.
 */
final class AuditLogSubscriber
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly ServerRegistry $servers,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ServingEvent::class => 'onServing',
            AfterSaveEvent::class => 'onAfterSave',
            AfterDeleteEvent::class => 'onAfterDelete',
        ];
    }

    public function onServing(ServingEvent $event): void
    {
        // A serving listener sees the raw request: a `403` throw here aborts before any
        // operation runs (the route-scoped JsonApiExceptionRenderer renders the status).
        // The PSR-7 header read works because JsonApiRequestInterface is a
        // ServerRequestInterface.
        if (\strtolower($event->request->getHeaderLine('X-Read-Only')) === 'on'
            && !\in_array($event->request->getMethod(), ['GET', 'HEAD'], true)
        ) {
            throw new AccessDeniedHttpException('The API is in read-only mode.');
        }
    }

    public function onAfterSave(AfterSaveEvent $event): void
    {
        $this->audit->record(
            $event->creating ? 'created' : 'updated',
            $event->type,
            $this->idOf($event->serverName, $event->type, $event->entity),
        );
    }

    public function onAfterDelete(AfterDeleteEvent $event): void
    {
        $this->audit->record('deleted', $event->type, $this->idOf($event->serverName, $event->type, $event->entity));
    }

    /**
     * Resolves the wire id off the **server the operation dispatched on** (every event
     * carries its `$serverName`): a type exposed only on a named server — the admin-only
     * `users` here — is not registered on the `default` server, so a multi-server-aware
     * audit resolves the serializer on the right one.
     */
    private function idOf(string $serverName, string $type, object $entity): string
    {
        return $this->servers->get($serverName)->serializerFor($type)->getId($entity);
    }
}
