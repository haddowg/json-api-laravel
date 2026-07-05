<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;

/**
 * Dispatched after an update commits, before the aggregate {@see AfterSaveEvent}.
 * A listener may **replace** the `200` response via {@see setResponse()}; the
 * handler reads the (possibly replaced) {@see response()} back. Deferred to
 * post-commit under an active Atomic Operations batch (replacement inert). Routed
 * to {@see \haddowg\JsonApiLaravel\Hook\ResourceLifecycleHooksInterface::afterUpdate()}.
 */
final class AfterUpdateEvent
{
    private ?DataResponse $response = null;

    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $entity,
        public readonly string $serverName,
    ) {}

    public function setResponse(?DataResponse $response): void
    {
        $this->response = $response;
    }

    public function response(): ?DataResponse
    {
        return $this->response;
    }
}
