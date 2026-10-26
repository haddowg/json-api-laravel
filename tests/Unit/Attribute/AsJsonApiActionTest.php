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

    public function test_safety_and_extensions_default_to_undeclared(): void
    {
        $attribute = new AsJsonApiAction(type: 'albums', path: 'publish');

        self::assertNull($attribute->readOnly);
        self::assertNull($attribute->destructive);
        self::assertNull($attribute->idempotent);
        self::assertSame([], $attribute->extensions);
    }

    public function test_it_passes_the_declared_safety_and_extensions_through(): void
    {
        $attribute = new AsJsonApiAction(
            type: 'albums',
            path: 'purge',
            readOnly: false,
            destructive: true,
            idempotent: true,
            extensions: ['x-workflows' => ['purge-albums']],
        );

        self::assertFalse($attribute->readOnly);
        self::assertTrue($attribute->destructive);
        self::assertTrue($attribute->idempotent);
        self::assertSame(['x-workflows' => ['purge-albums']], $attribute->extensions);
    }

    public function test_it_rejects_an_extension_key_without_the_x_prefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"workflows" is invalid');

        new AsJsonApiAction(type: 'albums', path: 'purge', extensions: ['workflows' => true]);
    }

    public function test_it_rejects_a_reserved_extension_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');

        new AsJsonApiAction(type: 'albums', path: 'purge', extensions: ['x-oai-thing' => true]);
    }
}
