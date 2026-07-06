<?php

declare(strict_types=1);

namespace Workbench\App\Async;

use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * `POST /jobs/-actions/complete` — the completion half of the async lifecycle: it
 * stands in for the job-status endpoint a client polls, returning a `303 See Other`
 * ({@see ActionContext::seeOther()}) that redirects to the resource the finished
 * operation produced. The witness that a custom action can drive the `303` leg of the
 * async story (ADR 0020, the twin of the bundle's `CompleteJobAction`).
 */
#[AsJsonApiAction(type: 'jobs', path: 'complete', scope: ActionScope::Collection, returns204: true)]
final readonly class CompleteJobAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): SeeOtherResponse
    {
        return $context->seeOther('http://localhost/api/albums/1');
    }
}
