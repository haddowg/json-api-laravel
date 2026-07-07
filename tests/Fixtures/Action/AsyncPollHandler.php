<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Action;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A discovery-scan witness for the async-completion action shape: a poll action whose
 * `responds` advertises both a `202` accept (a job document) and a `303` completion
 * redirect. The `handle()` return keeps a union, so the output guard does not constrain it;
 * the declared set round-trips to its cacheable scalar form for the OpenAPI projection.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'guarded', path: 'poll', methods: ['GET'], responds: [new Accepted('guarded-jobs'), new SeeOther()])]
final class AsyncPollHandler implements ActionHandlerInterface
{
    public function handle(ActionContext $context): AcceptedResponse|SeeOtherResponse
    {
        throw new \LogicException('Never invoked — this fixture only exercises discovery classification.');
    }
}
