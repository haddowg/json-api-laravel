<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Action;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Server\ResolvingServerInterface;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionDescriptor;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Action\FilteredCollection;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters\CountGadgetsAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ActionContext::class)]
#[CoversClass(FilteredCollection::class)]
final class ActionContextFilteredTest extends TestCase
{
    public function test_an_action_without_filters_has_no_filtered_collection(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The JSON:API action "count" on type "gadgets" takes no filters');

        $this->context(null)->filtered();
    }

    public function test_the_filtered_collection_is_built_once_on_first_use(): void
    {
        $built = 0;
        $context = $this->context(static function () use (&$built): FilteredCollection {
            ++$built;

            return new FilteredCollection(
                new InMemoryDataProvider('gadgets', []),
                'gadgets',
                new CollectionCriteria(new QueryParameters([], [], [], [], [])),
            );
        });

        self::assertSame(0, $built);
        self::assertSame($context->filtered(), $context->filtered());
        self::assertSame(1, $built);
    }

    public function test_each_rejects_a_batch_below_one(): void
    {
        $filtered = new FilteredCollection(
            new InMemoryDataProvider('gadgets', []),
            'gadgets',
            new CollectionCriteria(new QueryParameters([], [], [], [], [])),
        );

        $this->expectException(\InvalidArgumentException::class);

        $filtered->each(0);
    }

    /**
     * @param (\Closure(): FilteredCollection)|null $factory
     */
    private function context(?\Closure $factory): ActionContext
    {
        return new ActionContext(
            self::createStub(ResolvingServerInterface::class),
            new ActionDescriptor('gadgets', 'count', ['GET'], ActionScope::Collection, ActionInput::None, 'gadgets', 'gadgets', [['kind' => 'meta', 'ref' => null]], null, CountGadgetsAction::class, 'default'),
            null,
            null,
            self::createStub(JsonApiRequestInterface::class),
            new QueryParameters([], [], [], [], []),
            filteredFactory: $factory,
        );
    }
}
