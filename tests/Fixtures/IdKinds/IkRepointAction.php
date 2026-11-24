<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use Illuminate\Database\Eloquent\Model;

/**
 * Answers with the book it was called on, its writer the one the input document names, so the
 * book is read by its id and the writer resolved from linkage, whatever the key kind.
 */
#[AsJsonApiAction(type: 'ikBooks', path: 'repoint', methods: ['PATCH'], input: ActionInput::Document, inputType: 'ikBooks')]
final class IkRepointAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $book = $context->entity();
        $input = $context->input();
        \assert(\is_object($book) && \is_object($input));
        $writer = $input instanceof Model ? $input->getRelation('writer') : ($input instanceof IkBookRecord ? $input->writer : null);

        $answer = clone $book;
        if ($answer instanceof IkBook) {
            $answer->writer()->associate($writer instanceof Model ? $writer : null);
        } elseif ($answer instanceof IkBookRecord && ($writer === null || $writer instanceof IkWriterRecord)) {
            $answer->writer = $writer;
        }

        return $context->data($answer);
    }
}
