<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ContentType;

use haddowg\JsonApi\OpenApi\Metadata\AcceptedBody;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Takes scores as plain JSON described by a schema, as a form upload, or as plain text.
 *
 * @internal
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'import-scores',
    scope: ActionScope::Collection,
    input: ActionInput::Raw,
    responds: [new MetaResult()],
    accepts: [
        new AcceptedBody('application/json', schema: ['type' => 'object', 'properties' => ['score' => ['type' => 'integer']]]),
        new AcceptedBody('multipart/form-data'),
        'text/plain',
    ],
)]
final class ImportScoresAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['mediaType' => $context->requestMediaType(), 'body' => (string) $context->request()->getBody()]);
    }
}
