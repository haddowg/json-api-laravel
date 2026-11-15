<?php

declare(strict_types=1);

namespace Workbench\App\WriteTransaction;

use haddowg\JsonApi\Exception\AccessDenied;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A writing collection action: writes a note on the side.
 */
#[AsJsonApiAction(type: 'txWidgets', path: 'sweep', scope: ActionScope::Collection, responds: [new NoContent()])]
final readonly class SweepTxWidgets implements ActionHandlerInterface
{
    public function __construct(private TxSideWriter $writer) {}

    public function handle(ActionContext $context): NoContentResponse
    {
        $this->writer->write('swept');

        if (TxScenario::$actionThrows) {
            throw new AccessDenied('Refused by the test action.');
        }

        return $context->noContent();
    }
}
