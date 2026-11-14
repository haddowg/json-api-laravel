<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\PivotKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Server\ResourceRegistry;
use haddowg\JsonApiLaravel\Eloquent\BinaryUuidEncoder;
use haddowg\JsonApiLaravel\Server\FilterPathGuard;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkDefinition;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkDefinitionResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkLabelledDefinitionResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkPlaceholderTarget;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkTemplate;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PrefixedDefinitionEncoder;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * A pivot-row filter's path walks the pivot model. Its association to the relation's related
 * model is named by the related type, so another type serving that model under a different
 * encoder is no ambiguity; a model no named type serves is refused.
 *
 * @internal
 */
#[CoversClass(FilterPathGuard::class)]
final class PivotRowEncoderAmbiguityTest extends Orchestra
{
    #[Test]
    public function theRelatedTypeNamesThePivotRowsRelatedModel(): void
    {
        $registry = $this->registry(RowTemplateResource::class, PkDefinitionResource::class, PkLabelledDefinitionResource::class);

        FilterPathGuard::check($registry, new RowTemplateResource(), [
            'row-templates' => PkTemplate::class,
            'labelled-definitions' => PkDefinition::class,
            'placeholder-definitions' => PkDefinition::class,
        ]);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aPivotRowPathToAModelNoNamedTypeSettlesIsRefused(): void
    {
        $registry = $this->registry(RowTemplateResource::class, PkDefinitionResource::class, PrefixedTargetResource::class, BinaryTargetResource::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Filter "target" of type "row-templates" ends its path "pivot.targets" on ' . PkPlaceholderTarget::class . ', which the types "prefixed-targets" and "binary-targets" serve with different id encoders');

        FilterPathGuard::check($registry, new RowTemplateResource(), [
            'row-templates' => PkTemplate::class,
            'placeholder-definitions' => PkDefinition::class,
            'prefixed-targets' => PkPlaceholderTarget::class,
            'binary-targets' => PkPlaceholderTarget::class,
        ]);
    }

    /**
     * @param class-string<AbstractResource> ...$resources
     */
    private function registry(string ...$resources): ResourceRegistry
    {
        $registry = new ResourceRegistry();
        foreach ($resources as $resource) {
            $registry->register($resource);
        }

        return $registry;
    }
}

/**
 * @internal
 */
final class RowTemplateResource extends AbstractResource
{
    public static string $type = 'row-templates';

    public function fields(): array
    {
        return [
            Id::make()->numeric()->storedAs('campaign_id'),
            Str::make('name'),
            BelongsToMany::make('placeholders', 'placeholder-definitions')
                ->fields(Str::make('defaultOverride')->storedAs('default_override')->nullable()->build())
                ->withFilters(
                    WhereThroughIn::make('rowDefinition', 'pivot.definition'),
                    WhereThroughIn::make('target', 'pivot.targets'),
                ),
        ];
    }
}

/**
 * @internal
 */
final class PrefixedTargetResource extends AbstractResource
{
    public static string $type = 'prefixed-targets';

    public function fields(): array
    {
        return [Id::make()->encodeUsing(new PrefixedDefinitionEncoder()), Str::make('name')];
    }
}

/**
 * @internal
 */
final class BinaryTargetResource extends AbstractResource
{
    public static string $type = 'binary-targets';

    public function fields(): array
    {
        return [Id::make()->encodeUsing(new BinaryUuidEncoder()), Str::make('name')];
    }
}
