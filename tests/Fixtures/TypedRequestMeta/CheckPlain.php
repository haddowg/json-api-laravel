<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * `POST /tr-plains/-actions/check` reads a `tr-plains` document, a type declaring no resource
 * meta, and answers with the typed `data.meta` it was written with and the one sent.
 */
#[AsJsonApiAction(type: 'tr-plains', path: 'check', scope: ActionScope::Collection, input: ActionInput::Document, inputType: 'tr-plains', responds: [new MetaResult()])]
final class CheckPlain implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $body = $context->request()->getParsedBody();
        $data = \is_array($body) ? ($body['data'] ?? null) : null;

        return $context->meta([
            'typed' => $context->writtenMeta()->resource,
            'sent' => \is_array($data) ? ($data['meta'] ?? null) : null,
        ]);
    }
}
