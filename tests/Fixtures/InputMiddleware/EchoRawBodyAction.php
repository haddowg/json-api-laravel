<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\InputMiddleware;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Echoes a Raw-input action's body back: the bytes off the stream, and the decoded body for
 * a JSON upload.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'albums', path: 'echo-raw', scope: ActionScope::Collection, input: ActionInput::Raw, responds: [new MetaResult()], accepts: ['*/*', 'text/plain'])]
final class EchoRawBodyAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $request = $context->request();
        $json = \str_contains($request->getHeaderLine('Content-Type'), 'json');

        return $context->meta([
            'raw' => (string) $request->getBody(),
            'parsed' => $json ? $request->getParsedBody() : null,
        ]);
    }
}
