<?php

declare(strict_types=1);

namespace Workbench\App\Singleton;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;

/**
 * Archives the caller's inbox, `POST /inbox/-actions/archive`, and answers with it.
 */
#[AsJsonApiAction(type: 'inboxes', path: 'archive', asLink: true)]
final readonly class ArchiveInbox implements ActionHandlerInterface
{
    public function __construct(private DataPersisterRegistry $persisters) {}

    public function handle(ActionContext $context): DataResponse
    {
        $inbox = $context->entity();
        \assert($inbox !== null);

        Accessor::set($inbox, 'title', 'archived');
        $this->persisters->forType('inboxes')->update('inboxes', $inbox);

        return $context->data($inbox);
    }
}
