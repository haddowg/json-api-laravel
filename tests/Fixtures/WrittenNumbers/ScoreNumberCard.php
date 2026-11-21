<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\WrittenNumbers;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionInputFactoryInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Hydrates a `numberCards` input and answers with the PHP type of each number it holds.
 */
#[AsJsonApiAction(
    type: 'numberCards',
    path: 'score',
    scope: ActionScope::Collection,
    input: ActionInput::Document,
    inputType: 'numberCards',
    responds: [new MetaResult()],
)]
final class ScoreNumberCard implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new NumberCard();
    }

    public function handle(ActionContext $context): MetaResponse
    {
        $card = $context->input();
        \assert($card instanceof NumberCard);

        return $context->meta([
            'picked' => \get_debug_type($card->picked),
            'weight' => \get_debug_type($card->weight),
            'hooked' => $card->hookedType,
            'counts' => \is_array($card->counts) ? \array_map(\get_debug_type(...), $card->counts) : null,
            'weights' => \is_array($card->weights) ? \array_map(\get_debug_type(...), $card->weights) : null,
        ]);
    }
}
