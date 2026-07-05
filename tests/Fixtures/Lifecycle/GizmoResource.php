<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Lifecycle;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Hook\HookContext;
use haddowg\JsonApiLaravel\Hook\ResourceLifecycleHooksInterface;
use haddowg\JsonApiLaravel\Hook\ResourceLifecycleHooksTrait;

/**
 * The `gizmos` fixture resource for the resource lifecycle-hook suite: it opts into the
 * hook seam ({@see ResourceLifecycleHooksInterface} + {@see ResourceLifecycleHooksTrait}
 * no-op defaults) and delegates each overridden hook to a test-installed static callback
 * ({@see $callbacks}), so a test drives the abort/replace matrix per hook without a
 * bespoke resource per case. Exposed on both the `default` and `admin` servers so the
 * multi-server events suite can assert the per-server `serverName` payload.
 *
 * @internal
 */
#[AsJsonApiResource(server: ['default', 'admin'])]
final class GizmoResource extends AbstractResource implements ResourceLifecycleHooksInterface
{
    use ResourceLifecycleHooksTrait;

    public static string $type = 'gizmos';

    /**
     * Test-installed hook callbacks keyed by hook name; an absent key keeps the trait's
     * no-op default. A before callback may throw to abort; an after callback returns a
     * replacement response (or null to keep the handler's).
     *
     * @var array<string, callable>
     */
    public static array $callbacks = [];

    public static function reset(): void
    {
        self::$callbacks = [];
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Str::make('status'),
        ];
    }

    public function beforeCreate(object $entity, HookContext $context): void
    {
        $callback = self::$callbacks['beforeCreate'] ?? null;
        if ($callback !== null) {
            $callback($entity, $context);
        }
    }

    public function afterCreate(object $entity, HookContext $context): ?DataResponse
    {
        $callback = self::$callbacks['afterCreate'] ?? null;

        return $callback === null ? null : $callback($entity, $context);
    }

    public function beforeUpdate(object $entity, object $original, HookContext $context): void
    {
        $callback = self::$callbacks['beforeUpdate'] ?? null;
        if ($callback !== null) {
            $callback($entity, $original, $context);
        }
    }

    public function afterFetchOne(object $entity, HookContext $context): ?DataResponse
    {
        $callback = self::$callbacks['afterFetchOne'] ?? null;

        return $callback === null ? null : $callback($entity, $context);
    }

    /**
     * @param list<object> $items
     */
    public function afterFetchCollection(array $items, HookContext $context): ?DataResponse
    {
        $callback = self::$callbacks['afterFetchCollection'] ?? null;

        return $callback === null ? null : $callback($items, $context);
    }
}
