<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use haddowg\JsonApiLaravel\Event\AfterCreateEvent;
use haddowg\JsonApiLaravel\Event\BeforeCreateEvent;
use haddowg\JsonApiLaravel\Event\BeforeDeleteEvent;
use haddowg\JsonApiLaravel\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiLaravel\Event\BeforeUpdateEvent;

/**
 * Records the typed meta each write's lifecycle events carry.
 */
final class TrMetaRecorder
{
    /**
     * @var array<string, array{document: array<string, mixed>, resource: array<string, mixed>}>
     */
    public static array $seen = [];

    /**
     * The top-level `meta` each event's request carried as sent.
     *
     * @var array<string, mixed>
     */
    public static array $raw = [];

    public function handle(BeforeCreateEvent|AfterCreateEvent|BeforeUpdateEvent|BeforeDeleteEvent|BeforeRelationshipMutateEvent $event): void
    {
        $meta = $event->writtenMeta();
        $key = $event->type . ':' . (new \ReflectionClass($event))->getShortName();
        self::$seen[$key] = ['document' => $meta->document, 'resource' => $meta->resource];
        $body = $event->request->getParsedBody();
        self::$raw[$key] = \is_array($body) ? ($body['meta'] ?? null) : null;
    }
}
