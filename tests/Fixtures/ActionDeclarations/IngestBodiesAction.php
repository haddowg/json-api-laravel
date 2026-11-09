<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionDeclarations;

use haddowg\JsonApi\OpenApi\Metadata\AcceptedBody;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A Raw action whose `AcceptedBody` is read as a `Document` but names no `inputType`,
 * which fails discovery.
 *
 * @internal
 */
#[AsJsonApiAction(
    type: 'guarded',
    path: 'ingest-bodies',
    scope: ActionScope::Collection,
    input: ActionInput::Raw,
    responds: [new NoContent()],
    accepts: [new AcceptedBody('application/vnd.api+json', document: true)],
)]
final class IngestBodiesAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        throw new \LogicException('Never invoked; this fixture only exercises discovery.');
    }
}
