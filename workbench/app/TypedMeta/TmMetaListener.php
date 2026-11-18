<?php

declare(strict_types=1);

namespace Workbench\App\TypedMeta;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Event\AfterFetchCollectionEvent;
use haddowg\JsonApiLaravel\Server\ServerRegistry;

/**
 * An After hook that, asked by the `X-Note` header, answers a card collection with its
 * own meta: the note no hook supplies and an `open` count that wins over the extracted
 * one. Asked by `X-Bogus-Meta`, it adds a member the declaration does not name, and by
 * `X-Wrong-Type`, it sets `open` to a string.
 */
final readonly class TmMetaListener
{
    public function __construct(private ServerRegistry $servers) {}

    public function handle(AfterFetchCollectionEvent $event): void
    {
        if ($event->type !== 'tm-cards') {
            return;
        }

        $meta = match (true) {
            $event->request->hasHeader('X-Note') => ['note' => $event->request->getHeaderLine('X-Note'), 'open' => 99],
            $event->request->hasHeader('X-Bogus-Meta') => ['bogus' => true],
            $event->request->hasHeader('X-Wrong-Type') => ['open' => 'two'],
            default => null,
        };
        if ($meta === null) {
            return;
        }

        $event->setResponse(DataResponse::fromCollection($event->items, $this->servers->get($event->serverName)->serializerFor('tm-cards'))->withMeta($meta));
    }
}
