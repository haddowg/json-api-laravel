<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Server\ResourceRegistry;
use haddowg\JsonApiLaravel\Server\FilterPathGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pivot relation's `pivot.`-prefixed relationship filter starts at the pivot row, so a related
 * type that stores a relation as `pivot` makes the prefix ambiguous, and route registration and
 * `jsonapi:optimize` refuse it.
 *
 * @internal
 */
#[CoversClass(FilterPathGuard::class)]
final class PivotPrefixClashTest extends TestCase
{
    #[Test]
    public function aPivotRowFilterIsRefusedWhenTheRelatedTypeStoresARelationAsPivot(): void
    {
        $registry = new ResourceRegistry();
        $registry->register(ClashTemplateResource::class);
        $registry->register(ClashDefinitionResource::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "placeholders" of type "clash-templates" declares filter "unused" on "pivot.targets", which starts at the pivot row, but its related type "clash-definitions" has a relation "owner" stored as "pivot"');

        FilterPathGuard::check($registry, new ClashTemplateResource());
    }

    #[Test]
    public function aPivotRowFilterIsAcceptedWhenNoRelatedRelationIsStoredAsPivot(): void
    {
        $registry = new ResourceRegistry();
        $registry->register(ClashTemplateResource::class);
        $registry->register(PlainDefinitionResource::class);

        FilterPathGuard::check($registry, new ClashTemplateResource());
        $this->addToAssertionCount(1);
    }
}

/**
 * @internal
 */
final class ClashTemplateResource extends AbstractResource
{
    public static string $type = 'clash-templates';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsToMany::make('placeholders', 'clash-definitions')
                ->fields(Str::make('note')->nullable()->build())
                ->withFilters(
                    WhereDoesntHave::make('unused', 'pivot.targets'),
                    WhereThrough::make('targetName', 'pivot.targets.name'),
                ),
        ];
    }
}

/**
 * @internal
 */
final class ClashDefinitionResource extends AbstractResource
{
    public static string $type = 'clash-definitions';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('owner', 'clash-templates')->storedAs('pivot'),
        ];
    }
}

/**
 * @internal
 */
final class PlainDefinitionResource extends AbstractResource
{
    public static string $type = 'clash-definitions';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('owner', 'clash-templates'),
        ];
    }
}
