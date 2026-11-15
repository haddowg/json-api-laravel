<?php

declare(strict_types=1);

namespace Workbench\App\WriteTransaction;

use haddowg\JsonApi\Exception\AccessDenied;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A GET action that writes anyway, to show a GET runs without a transaction.
 */
#[AsJsonApiAction(type: 'txWidgets', path: 'peek', methods: ['GET'], responds: [new NoContent()])]
final readonly class PeekTxWidget implements ActionHandlerInterface
{
    public function __construct(private TxSideWriter $writer) {}

    public function handle(ActionContext $context): NoContentResponse
    {
        $this->writer->write('peeked');

        if (TxScenario::$actionThrows) {
            throw new AccessDenied('Refused by the test action.');
        }

        return $context->noContent();
    }
}
