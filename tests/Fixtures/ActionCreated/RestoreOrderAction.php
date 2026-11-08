<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionCreated;

use haddowg\JsonApi\OpenApi\Metadata\ActionCreated;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Declares both a `200` and a `201` document, so the handler picks: order `2` is treated as
 * recreated and answered `201`, any other keeps the `200`.
 */
#[AsJsonApiAction(type: 'orders', path: 'restore', responds: [new ActionResource('orders'), new ActionCreated('orders')])]
final class RestoreOrderAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $order = $context->entity();
        \assert($order instanceof OrderRecord);
        $response = $context->data($order);

        return $order->id === '2' ? $response->withStatus(201) : $response;
    }
}
