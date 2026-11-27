<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Server;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Server\PivotStorageGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relation with stored pivot fields needs its related type's provider to read pivot rows,
 * so route registration and `jsonapi:optimize` refuse one served by a provider that can't.
 *
 * @internal
 */
#[CoversClass(PivotStorageGuard::class)]
final class PivotStorageGuardTest extends TestCase
{
    #[Test]
    public function storedPivotFieldsServedByAProviderThatReadsNoPivotRowsAreRefused(): void
    {
        $guard = new PivotStorageGuard(static fn(): DataProviderRegistry => new DataProviderRegistry([new NoPivotRowsProvider()]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The relation "slots" on type "pivot-storage-shelves" declares stored pivot fields, but ' . NoPivotRowsProvider::class . ', the provider of its related type "pivot-storage-items", reads no pivot rows. Implement haddowg\JsonApiLaravel\DataProvider\PivotAwareProviderInterface on it');

        $guard->check('pivot-storage-shelves', new PivotStorageShelfResource());
    }

    #[Test]
    public function storedPivotFieldsServedByAProviderThatReadsPivotRowsAreAccepted(): void
    {
        $guard = new PivotStorageGuard(static fn(): DataProviderRegistry => new DataProviderRegistry([new InMemoryDataProvider('pivot-storage-items', [])]));

        $guard->check('pivot-storage-shelves', new PivotStorageShelfResource());
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function computedPivotFieldsNeedNoProviderThatReadsPivotRows(): void
    {
        $guard = new PivotStorageGuard(static fn(): DataProviderRegistry => new DataProviderRegistry([new NoPivotRowsProvider()]));

        $guard->check('pivot-storage-shelves', new PivotStorageComputedShelfResource());
        $this->addToAssertionCount(1);
    }
}

/**
 * @internal
 *
 * @extends AbstractDataProvider<object>
 */
final class NoPivotRowsProvider extends AbstractDataProvider
{
    public function supports(string $type): bool
    {
        return $type === 'pivot-storage-items';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return new CollectionResult([]);
    }
}

/**
 * @internal
 */
final class PivotStorageShelfResource extends AbstractResource
{
    public static string $type = 'pivot-storage-shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsToMany::make('slots', 'pivot-storage-items')->fields(Integer::make('position')),
        ];
    }
}

/**
 * @internal
 */
final class PivotStorageComputedShelfResource extends AbstractResource
{
    public static string $type = 'pivot-storage-shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsToMany::make('slots', 'pivot-storage-items')->fields(Str::make('label')->computedUsing(static fn(): string => 'x')),
        ];
    }
}
