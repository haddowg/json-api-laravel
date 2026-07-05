<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Attribute;

use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AsJsonApiAction::class)]
final class AsJsonApiActionTest extends TestCase
{
    public function test_it_accepts_a_single_literal_path_segment(): void
    {
        $attribute = new AsJsonApiAction(type: 'albums', path: 'publish-now.v2');

        self::assertSame('publish-now.v2', $attribute->path);
        self::assertSame(['POST'], $attribute->methods);
    }

    public function test_it_rejects_a_path_with_a_slash(): void
    {
        $this->expectException(\LogicException::class);

        new AsJsonApiAction(type: 'albums', path: 'publish/now');
    }

    public function test_it_rejects_a_path_that_is_a_route_placeholder(): void
    {
        $this->expectException(\LogicException::class);

        new AsJsonApiAction(type: 'albums', path: '{action}');
    }

    public function test_it_rejects_an_empty_path(): void
    {
        $this->expectException(\LogicException::class);

        new AsJsonApiAction(type: 'albums', path: '');
    }

    public function test_it_rejects_an_empty_methods_list(): void
    {
        $this->expectException(\LogicException::class);

        new AsJsonApiAction(type: 'albums', path: 'publish', methods: []);
    }

    public function test_it_rejects_an_unknown_http_verb(): void
    {
        $this->expectException(\LogicException::class);

        new AsJsonApiAction(type: 'albums', path: 'publish', methods: ['FETCH']);
    }

    public function test_it_accepts_a_collection_scope_action(): void
    {
        $attribute = new AsJsonApiAction(type: 'albums', path: 'purge', scope: ActionScope::Collection);

        self::assertSame(ActionScope::Collection, $attribute->scope);
    }
}
