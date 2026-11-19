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

    public function handle(BeforeCreateEvent|AfterCreateEvent|BeforeUpdateEvent|BeforeDeleteEvent|BeforeRelationshipMutateEvent $event): void
    {
        $meta = $event->writtenMeta();
        self::$seen[$event->type . ':' . (new \ReflectionClass($event))->getShortName()] = ['document' => $meta->document, 'resource' => $meta->resource];
    }
}
