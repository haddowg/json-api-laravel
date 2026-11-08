<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionCreated;

use haddowg\JsonApi\OpenApi\Metadata\ActionCreated;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Creates a copy of the order and answers with it. It declares only an
 * {@see ActionCreated}, so the response is sent as `201` without the handler setting a status.
 */
#[AsJsonApiAction(type: 'orders', path: 'duplicate', responds: [new ActionCreated('orders')])]
final class DuplicateOrderAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $order = $context->entity();
        \assert($order instanceof OrderRecord);
        $copy = new OrderRecord($order->id . '-copy', $order->reference . ' (copy)');

        return $context->data($copy)->withHeader('Location', 'http://localhost/api/orders/' . $copy->id);
    }
}
