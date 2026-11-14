<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\PivotKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Server\ResourceRegistry;
use haddowg\JsonApiLaravel\Server\FilterPathGuard;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkDefinition;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkDefinitionResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkLabelledDefinitionResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkTemplate;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PrefixedDefinitionEncoder;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * A filter path ending on a model several types serve with different id encoders needs a
 * type its declaration names to say which encoder decodes its values. Route registration and
 * `jsonapi:optimize` refuse one that has none; types sharing an encoder stay allowed.
 *
 * @internal
 */
#[CoversClass(FilterPathGuard::class)]
final class FilterEncoderAmbiguityTest extends Orchestra
{
    #[Test]
    public function aPathEndingOnAModelNoNamedTypeSettlesIsRefused(): void
    {
        $registry = $this->registry(OrphanTemplateResource::class, PkDefinitionResource::class, PkLabelledDefinitionResource::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Filter "definition" of type "orphan-templates" ends its path "placeholderRows.definition" on ' . PkDefinition::class . ', which the types "placeholder-definitions" and "labelled-definitions" serve with different id encoders, and no type the declaration names serves it.');

        FilterPathGuard::check($registry, new OrphanTemplateResource(), [
            'orphan-templates' => PkTemplate::class,
            'placeholder-definitions' => PkDefinition::class,
            'labelled-definitions' => PkDefinition::class,
        ]);
    }

    #[Test]
    public function aRelationsRelatedTypeNamesTheModel(): void
    {
        $registry = $this->registry(NamingTemplateResource::class, PkDefinitionResource::class, PkLabelledDefinitionResource::class);

        FilterPathGuard::check($registry, new NamingTemplateResource(), [
            'naming-templates' => PkTemplate::class,
            'labelled-definitions' => PkDefinition::class,
            'placeholder-definitions' => PkDefinition::class,
        ]);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function typesSharingAnEncoderStayAllowed(): void
    {
        $registry = $this->registry(OrphanTemplateResource::class, PkLabelledDefinitionResource::class, TwinLabelledDefinitionResource::class);

        FilterPathGuard::check($registry, new OrphanTemplateResource(), [
            'orphan-templates' => PkTemplate::class,
            'labelled-definitions' => PkDefinition::class,
            'twin-labelled-definitions' => PkDefinition::class,
        ]);
        $this->addToAssertionCount(1);
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
final class OrphanTemplateResource extends AbstractResource
{
    public static string $type = 'orphan-templates';

    public function fields(): array
    {
        return [Id::make()->numeric()->storedAs('campaign_id'), Str::make('name')];
    }

    public function filters(): array
    {
        return [WhereThroughIn::make('definition', 'placeholderRows.definition')];
    }
}

/**
 * @internal
 */
final class NamingTemplateResource extends AbstractResource
{
    public static string $type = 'naming-templates';

    public function fields(): array
    {
        return [
            Id::make()->numeric()->storedAs('campaign_id'),
            Str::make('name'),
            BelongsToMany::make('placeholders', 'placeholder-definitions')
                ->withFilters(WhereThroughIn::make('templateOf', 'usages.definition')),
        ];
    }

    public function filters(): array
    {
        return [WhereThroughIn::make('definition', 'placeholderRows.definition')];
    }
}

/**
 * @internal
 */
final class TwinLabelledDefinitionResource extends AbstractResource
{
    public static string $type = 'twin-labelled-definitions';

    public function fields(): array
    {
        return [Id::make()->encodeUsing(new PrefixedDefinitionEncoder()), Str::make('name')];
    }
}
