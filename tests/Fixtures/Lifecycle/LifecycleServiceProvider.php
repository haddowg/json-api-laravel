<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Lifecycle;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Phase-4 lifecycle fixtures over writable in-memory providers/persisters,
 * seeded with a `gizmos` row (the hook / events subject) and a `relics` row (the
 * response-header subject). Registered explicitly (the fixtures live outside the scanned
 * `app/JsonApi` path), so this provider never perturbs the workbench music/security suites.
 *
 * @internal
 */
final class LifecycleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([GizmoResource::class, RelicResource::class]);

        $gizmos = new InMemoryDataProvider(
            'gizmos',
            ['1' => new Gizmo(id: '1', name: 'Original', status: 'draft')],
            identify: self::identify(),
            assignId: self::assignId(),
        );
        JsonApi::provider($gizmos);
        JsonApi::persister(new InMemoryDataPersister('gizmos', $gizmos->store(), static fn(): Gizmo => new Gizmo()));

        $relics = new InMemoryDataProvider(
            'relics',
            ['1' => new Gizmo(id: '1', name: 'Ancient', status: 'kept')],
            identify: self::identify(),
            assignId: self::assignId(),
        );
        JsonApi::provider($relics);
        JsonApi::persister(new InMemoryDataPersister('relics', $relics->store(), static fn(): Gizmo => new Gizmo()));
    }

    private static function identify(): \Closure
    {
        return static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
    }

    private static function assignId(): \Closure
    {
        return static function (object $item, string $id): void {
            Accessor::set($item, 'id', $id);
        };
    }
}
