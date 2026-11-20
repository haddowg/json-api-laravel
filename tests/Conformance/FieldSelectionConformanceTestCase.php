<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Schema\Profile\FieldSelectionProfile;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Testing\SchemaConformanceTrait;
use haddowg\JsonApiLaravel\Testing\SchemaDocumentKind;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\FieldSelection\Panel;
use Workbench\App\FieldSelection\PanelResource;

/**
 * The field-selection conformance witness, run against both providers, the Laravel twin
 * of the Symfony bundle's `FieldSelectionConformanceTestCase`: a
 * `fields[TYPE]=<attribute>.<key>` member renders only that key of an attribute declaring
 * selectable keys, wherever the type renders, and the response applies and advertises
 * the Field Selection profile only when it narrows something.
 */
abstract class FieldSelectionConformanceTestCase extends Orchestra
{
    use SchemaConformanceTrait;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

    private const string NARROWED = 'application/vnd.api+json; profile="' . FieldSelectionProfile::URI . '"';

    /**
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, $this->conformanceServiceProvider()];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.base_uri', 'http://localhost/api');
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    protected function seedConformanceData(): void {}

    /**
     * Clears whatever the data layer keeps between requests, so the next read loads from
     * the store.
     */
    protected function detachPersistedState(): void {}

    protected function setUp(): void
    {
        PanelResource::$summaryRequests = [];

        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aKeyMemberRendersOnlyThatKey(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=name,values.cap,values.countries');

        $response->assertOk();
        self::assertSame(['name' => 'Seed', 'values' => ['cap' => '10', 'countries' => 'GB,FR']], $this->attributesOf($response));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aCollectionRendersTheSelectionForEveryResource(): void
    {
        $response = $this->fetch('/api/panels?fields[panels]=values.region');

        $response->assertOk();
        self::assertSame(['values' => ['region' => 'EU']], $response->json('data.0.attributes'));
        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function listingTheWholeAttributeBesideAKeyRendersAllOfIt(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=values,values.cap');

        self::assertSame(['values' => Panel::SEED_VALUES], $this->attributesOf($response));
        self::assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:extensions-and-profiles')]
    public function noFieldsetLeavesTheResponseUnchanged(): void
    {
        $response = $this->fetch('/api/panels/1');

        self::assertSame(Panel::SEED_VALUES, $this->attributesOf($response)['values'] ?? null);
        self::assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
        self::assertArrayNotHasKey('profile', $this->jsonApiOf($response));
    }

    #[Test]
    #[Group('spec:extensions-and-profiles')]
    public function aFieldsetNarrowingNothingAdvertisesNoProfile(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=name,values');

        self::assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
        self::assertArrayNotHasKey('profile', $this->jsonApiOf($response));
    }

    #[Test]
    #[Group('spec:extensions-and-profiles')]
    public function aNarrowedResponseAdvertisesTheProfileWithoutTheClientAskingForIt(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=values.cap');

        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
        self::assertSame([FieldSelectionProfile::URI], $this->jsonApiOf($response)['profile'] ?? null);
        self::assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function anExtractHookReceivesTheSelectedKeys(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=summary.count');

        self::assertSame(['summary' => ['count' => 2]], $this->attributesOf($response));
        self::assertSame([['count']], PanelResource::$summaryRequests);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function anExtractHookReceivesNullWhenTheAttributeIsTakenWhole(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=summary');

        self::assertSame(['summary' => ['count' => 2, 'width' => 40]], $this->attributesOf($response));
        self::assertSame([null], PanelResource::$summaryRequests);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aMapReadByAModelTypedHookIsSelected(): void
    {
        self::assertSame(['layout' => ['theme' => 'grid']], $this->attributesOf($this->fetch('/api/panels/1?fields[panels]=layout.theme')));
        self::assertSame(['layout' => ['region' => 'EU', 'theme' => 'grid']], $this->attributesOf($this->fetch('/api/panels/1?fields[panels]=layout')));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('openapi')]
    public function aNarrowedResponseMatchesTheGeneratedSchema(): void
    {
        // `layout` requires both its keys in the schema, so the narrowed value only
        // validates once the testing kit narrows the attribute's schema too.
        $response = $this->fetch('/api/panels/1?fields[panels]=name,layout.theme,values.cap');
        self::assertSame(['theme' => 'grid'], $this->attributesOf($response)['layout'] ?? null);
        $this->assertResponseMatchesGeneratedSchema($response, 'panels', SchemaDocumentKind::Single);

        $collection = $this->fetch('/api/panels?fields[panels]=layout.region');
        $this->assertResponseMatchesGeneratedSchema($collection, 'panels', SchemaDocumentKind::Collection);

        $whole = $this->fetch('/api/panels/1?fields[panels]=layout,layout.theme');
        $this->assertResponseMatchesGeneratedSchema($whole, 'panels', SchemaDocumentKind::Single);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aRepeatingGroupIsSelectedAsOneKey(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=groups.rows');

        self::assertSame(['groups' => ['rows' => [['a' => 1], ['a' => 2]]]], $this->attributesOf($response));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:includes')]
    public function aSelectionAppliesToIncludedResources(): void
    {
        $response = $this->fetch('/api/panels/1?include=curator&fields[panels]=name,curator&fields[curators]=stats.rating');

        $response->assertOk();
        self::assertSame(['stats' => ['rating' => 5]], $response->json('included.0.attributes'));
        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:includes')]
    public function anIncludeNarrowingNothingAdvertisesNoProfile(): void
    {
        $response = $this->fetch('/api/panels/1?include=curator&fields[curators]=stats');

        $response->assertOk();
        self::assertSame(['stats' => ['rating' => 5, 'reviews' => 12, 'secret' => 1]], $response->json('included.0.attributes'));
        self::assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aSelectionAppliesToARelatedEndpoint(): void
    {
        $response = $this->fetch('/api/panels/1/curator?fields[curators]=stats.reviews');

        $response->assertOk();
        self::assertSame(['stats' => ['reviews' => 12]], $this->attributesOf($response));
        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aNestedKeyMemberRendersOnlyItsPath(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=address.geo.lat');

        $response->assertOk();
        self::assertSame(['address' => ['geo' => ['lat' => 51.5]]], $this->attributesOf($response));
        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aNestedPathSelectsNothingElseOfItsAncestors(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=name,address.postcode,address.geo.lng');

        self::assertSame(['name' => 'Seed', 'address' => ['postcode' => 'AB1 2CD', 'geo' => ['lng' => -0.1]]], $this->attributesOf($response));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aKeyListedWholeBesideAPathIntoItRendersWholeAtEveryDepth(): void
    {
        self::assertSame(
            ['address' => ['geo' => Panel::SEED_ADDRESS['geo']]],
            $this->attributesOf($this->fetch('/api/panels/1?fields[panels]=address.geo.lat,address.geo')),
        );

        $whole = $this->fetch('/api/panels/1?fields[panels]=address,address.geo.lat');
        self::assertSame(['address' => Panel::SEED_ADDRESS], $this->attributesOf($whole));
        self::assertSame(self::MEDIA_TYPE, $whole->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:includes')]
    public function aNestedSelectionAppliesToIncludedResources(): void
    {
        $response = $this->fetch('/api/panels/1?include=curator&fields[panels]=curator&fields[curators]=office.geo.lat');

        $response->assertOk();
        self::assertSame(['office' => ['geo' => ['lat' => 48.8]]], $response->json('included.0.attributes'));
        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aNestedSelectionAppliesToARelatedEndpoint(): void
    {
        $response = $this->fetch('/api/panels/1/curator?fields[curators]=office.city,office.geo.lng');

        $response->assertOk();
        self::assertSame(['office' => ['city' => 'Paris', 'geo' => ['lng' => 2.3]]], $this->attributesOf($response));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('openapi')]
    public function aNestedNarrowedResponseMatchesTheGeneratedSchema(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=name,address.postcode,address.geo.lat');

        self::assertSame(['name' => 'Seed', 'address' => ['postcode' => 'AB1 2CD', 'geo' => ['lat' => 51.5]]], $this->attributesOf($response));
        $this->assertResponseMatchesGeneratedSchema($response, 'panels', SchemaDocumentKind::Single);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('openapi')]
    public function theFieldsParameterDocumentsEachNestedPath(): void
    {
        $factory = $this->app?->make(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $paths = \json_encode($factory->forServer()->toArray()['paths'] ?? [], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        // Encoded as JSON, so each pattern's backslash is doubled.
        self::assertStringContainsString('"pattern":"^address\\\\.(?:[a-z]+)$"', $paths);
        self::assertStringContainsString('"pattern":"^address\\\\.geo\\\\.(?:[a-z]+)$"', $paths);
        self::assertStringNotContainsString('^address\\\\.history\\\\.', $paths);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aNestedKeyTheObjectRejectsIsThe400(): void
    {
        $this->assertUnrecognized('/api/panels/1?fields[panels]=address.geo.Alt', 'address.geo.Alt');
        $this->assertUnrecognized('/api/panels/1/curator?fields[curators]=office.geo.alt', 'office.geo.alt');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aPathBelowAKeyThatIsNotSelectableIsThe400(): void
    {
        $this->assertUnrecognized('/api/panels/1?fields[panels]=address.postcode.x', 'address.postcode.x');
        $this->assertUnrecognized('/api/panels/1?fields[panels]=address.history.street', 'address.history.street');
        $this->assertUnrecognized('/api/panels/1?fields[panels]=values.cap.x', 'values.cap.x');
        $this->assertUnrecognized('/api/panels/1?fields[panels]=address..lat', 'address..lat');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aRelatedEndpointNarrowingNothingAdvertisesNoProfile(): void
    {
        $response = $this->fetch('/api/panels/1/curator?fields[curators]=name');

        $response->assertOk();
        self::assertSame(['name' => 'Ada'], $this->attributesOf($response));
        self::assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aWildcardSelectsTheKeyFromEveryItem(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=rows.*.label');

        $response->assertOk();
        self::assertSame(['rows' => [['label' => 'A'], ['label' => 'B']]], $this->attributesOf($response));
        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function anItemLackingTheSelectedMemberRendersAsAnEmptyObject(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=rows.*.note');

        self::assertStringContainsString('"attributes":{"rows":[{"note":"first"},{}]}', (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aWildcardKeepsAnEmptyListEmpty(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=archive.*.label');

        $response->assertOk();
        self::assertStringContainsString('"attributes":{"archive":[]}', (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aWildcardWithNothingAfterItSelectsTheWholeList(): void
    {
        $whole = $this->attributesOf($this->fetch('/api/panels/1?fields[panels]=rows'));

        $wildcard = $this->fetch('/api/panels/1?fields[panels]=rows.*');
        self::assertSame($whole, $this->attributesOf($wildcard));
        self::assertCount(2, \is_array($whole['rows'] ?? null) ? $whole['rows'] : []);
        self::assertSame(self::MEDIA_TYPE, $wildcard->headers->get('Content-Type'));
        self::assertSame(['version' => '1.1'], $this->jsonApiOf($wildcard));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aNestedListNamedThroughAWildcardAloneIsSelectedWhole(): void
    {
        self::assertSame(
            $this->attributesOf($this->fetch('/api/panels/1?fields[panels]=rows.*.cells')),
            $this->attributesOf($this->fetch('/api/panels/1?fields[panels]=rows.*.cells.*')),
        );
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aListListedWholeBesideAWildcardPathRendersWhole(): void
    {
        $whole = $this->attributesOf($this->fetch('/api/panels/1?fields[panels]=rows'));

        $listed = $this->fetch('/api/panels/1?fields[panels]=rows,rows.*.label');
        self::assertSame($whole, $this->attributesOf($listed));
        self::assertSame(self::MEDIA_TYPE, $listed->headers->get('Content-Type'));

        $wildcard = $this->fetch('/api/panels/1?fields[panels]=rows.*,rows.*.label');
        self::assertSame($whole, $this->attributesOf($wildcard));
        self::assertSame(self::MEDIA_TYPE, $wildcard->headers->get('Content-Type'));
        self::assertSame(
            ['rows' => [['size' => ['w' => 1, 'h' => 2]], ['size' => ['w' => 3, 'h' => 4]]]],
            $this->attributesOf($this->fetch('/api/panels/1?fields[panels]=rows.*.size,rows.*.size.w')),
        );
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aWildcardPathContinuesIntoNestedObjectsAndLists(): void
    {
        self::assertSame(
            ['rows' => [['label' => 'A', 'size' => ['w' => 1]], ['label' => 'B', 'size' => ['w' => 3]]]],
            $this->attributesOf($this->fetch('/api/panels/1?fields[panels]=rows.*.label,rows.*.size.w')),
        );
        self::assertSame(
            ['rows' => [['cells' => [['v' => 'x'], ['v' => 'y']]], ['cells' => []]]],
            $this->attributesOf($this->fetch('/api/panels/1?fields[panels]=rows.*.cells.*.v')),
        );
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:includes')]
    public function aWildcardSelectionAppliesToIncludedResources(): void
    {
        $response = $this->fetch('/api/panels/1?include=curator&fields[panels]=curator&fields[curators]=exhibits.*.title');

        $response->assertOk();
        self::assertSame(['exhibits' => [['title' => 'Light'], ['title' => 'Sound']]], $response->json('included.0.attributes'));
        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aWildcardSelectionAppliesToARelatedEndpoint(): void
    {
        $response = $this->fetch('/api/panels/1/curator?fields[curators]=exhibits.*.year');

        $response->assertOk();
        self::assertSame(['exhibits' => [['year' => 2001], ['year' => 2004]]], $this->attributesOf($response));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('openapi')]
    public function aWildcardNarrowedResponseMatchesTheGeneratedSchema(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=name,rows.*.label,rows.*.size.w,rows.*.cells.*.v,archive.*.label');

        $response->assertOk();
        $this->assertResponseMatchesGeneratedSchema($response, 'panels', SchemaDocumentKind::Single);

        $collection = $this->fetch('/api/panels?fields[panels]=rows.*.note');
        $this->assertResponseMatchesGeneratedSchema($collection, 'panels', SchemaDocumentKind::Collection);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('openapi')]
    public function theFieldsParameterDocumentsEachWildcardPath(): void
    {
        $factory = $this->app?->make(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $paths = \json_encode($factory->forServer()->toArray()['paths'] ?? [], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        // Encoded as JSON, so each pattern's backslash is doubled.
        self::assertStringContainsString('"pattern":"^rows\\\\.\\\\*\\\\.(?:[a-z]+)$"', $paths);
        self::assertStringContainsString('"pattern":"^rows\\\\.\\\\*\\\\.size\\\\.(?:[a-z])$"', $paths);
        self::assertStringContainsString('"pattern":"^rows\\\\.\\\\*\\\\.cells\\\\.\\\\*\\\\.(?:v)$"', $paths);
        self::assertStringContainsString('"pattern":"^exhibits\\\\.\\\\*\\\\.(?:[a-z]+)$"', $paths);
        self::assertStringNotContainsString('^tags\\\\.', $paths);
        self::assertStringNotContainsString('^address\\\\.history\\\\.', $paths);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aWildcardOverAOneOfRendersTheKeysEachItemsVariantAccepts(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=blocks.*.title,blocks.*.url');

        $response->assertOk();
        self::assertSame(
            ['blocks' => [['type' => 'text', 'title' => 'Intro'], ['type' => 'image', 'url' => '/a.png'], ['type' => 'divider']]],
            $this->attributesOf($response),
        );
        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
        self::assertSame(
            ['blocks' => [['type' => 'text', 'meta' => ['lang' => 'en']], ['type' => 'image'], ['type' => 'divider']]],
            $this->attributesOf($this->fetch('/api/panels/1?fields[panels]=blocks.*.meta.lang')),
        );
        self::assertSame(
            $this->attributesOf($this->fetch('/api/panels/1?fields[panels]=blocks')),
            $this->attributesOf($this->fetch('/api/panels/1?fields[panels]=blocks.*')),
        );
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('openapi')]
    public function aOneOfListNarrowedResponseMatchesTheGeneratedSchema(): void
    {
        $response = $this->fetch('/api/panels/1?fields[panels]=name,blocks.*.title,blocks.*.meta.lang,blocks.*.url');

        $response->assertOk();
        $this->assertResponseMatchesGeneratedSchema($response, 'panels', SchemaDocumentKind::Single);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('openapi')]
    public function theFieldsParameterDocumentsEveryVariantsPaths(): void
    {
        $factory = $this->app?->make(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $paths = \json_encode($factory->forServer()->toArray()['paths'] ?? [], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        self::assertStringContainsString('"pattern":"^blocks\\\\.\\\\*\\\\.(?:title|body|meta)$"', $paths);
        self::assertStringContainsString('"pattern":"^blocks\\\\.\\\\*\\\\.meta\\\\.(?:lang|tone)$"', $paths);
        self::assertStringContainsString('"pattern":"^blocks\\\\.\\\\*\\\\.(?:url)$"', $paths);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aWildcardKeyNoVariantAcceptsIsThe400(): void
    {
        $this->assertUnrecognized('/api/panels/1?fields[panels]=blocks.*.style', 'blocks.*.style');
        $this->assertUnrecognized('/api/panels/1?fields[panels]=blocks.*.title.x', 'blocks.*.title.x');
        $this->assertUnrecognized('/api/panels/1?fields[panels]=blocks.*.meta.x', 'blocks.*.meta.x');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aWildcardOnAnythingButAListOfSelectableObjectsIsThe400(): void
    {
        $this->assertUnrecognized('/api/panels/1?fields[panels]=name.*', 'name.*');
        $this->assertUnrecognized('/api/panels/1?fields[panels]=values.*', 'values.*');
        $this->assertUnrecognized('/api/panels/1?fields[panels]=address.geo.*', 'address.geo.*');
        $this->assertUnrecognized('/api/panels/1?fields[panels]=tags.*', 'tags.*');
        $this->assertUnrecognized('/api/panels/1?fields[panels]=address.history.*', 'address.history.*');
        $this->assertUnrecognized('/api/panels/1?fields[panels]=address.history.*.street', 'address.history.*.street');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function anIndexSegmentIsThe400(): void
    {
        $this->assertUnrecognized('/api/panels/1?fields[panels]=rows.0', 'rows.0');
        $this->assertUnrecognized('/api/panels/1?fields[panels]=rows.0.label', 'rows.0.label');
        $this->assertUnrecognized('/api/panels/1?fields[panels]=rows.*.cells.1.v', 'rows.*.cells.1.v');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:atomic')]
    public function aSelectionAppliesToAtomicResults(): void
    {
        $response = $this->atomic([[
            'op' => 'add',
            'data' => ['type' => 'panels', 'attributes' => ['name' => 'New', 'values' => ['cap' => '7', 'region' => 'NA']]],
        ]], 'fields[panels]=values.cap');

        $response->assertOk();
        self::assertSame(['values' => ['cap' => '7']], $response->json('atomic:results.0.data.attributes'));
        self::assertStringContainsString('profile="' . FieldSelectionProfile::URI . '"', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:atomic')]
    public function anAtomicBatchNarrowingNothingAdvertisesNoProfile(): void
    {
        $response = $this->atomic([[
            'op' => 'add',
            'data' => ['type' => 'panels', 'attributes' => ['name' => 'New', 'values' => ['cap' => '7']]],
        ]], 'fields[panels]=name');

        $response->assertOk();
        self::assertSame(['name' => 'New'], $response->json('atomic:results.0.data.attributes'));
        self::assertStringNotContainsString('profile=', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:crud')]
    public function aWriteStoresTheWholeValueAndAnswersWithTheSelection(): void
    {
        $values = ['cap' => '20', 'countries' => 'DE', 'region' => 'EU'];
        $response = $this->json('PATCH', '/api/panels/1?fields[panels]=values.cap', ['data' => [
            'type' => 'panels',
            'id' => '1',
            'attributes' => ['values' => $values],
        ]], ['Accept' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE]);

        $response->assertOk();
        self::assertSame(['values' => ['cap' => '20']], $this->attributesOf($response));
        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));

        $this->detachPersistedState();
        self::assertSame($values, $this->attributesOf($this->fetch('/api/panels/1'))['values'] ?? null);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aNotSparseFieldRendersWholeUnlessItsKeysAreSelected(): void
    {
        $whole = $this->fetch('/api/pinboards/1?fields[pinboards]=name');
        self::assertSame(['name' => 'Seed', 'values' => Panel::SEED_VALUES], $this->attributesOf($whole));
        self::assertSame(self::MEDIA_TYPE, $whole->headers->get('Content-Type'));

        $selected = $this->fetch('/api/pinboards/1?fields[pinboards]=name,values.cap');
        self::assertSame(['name' => 'Seed', 'values' => ['cap' => '10']], $this->attributesOf($selected));
        self::assertSame(self::NARROWED, $selected->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aKeyThePatternRejectsIsTheUnrecognizedFieldsetMember400(): void
    {
        $this->assertUnrecognized('/api/panels/1?fields[panels]=values.Cap', 'values.Cap');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aKeyThePredicateRejectsIsThe400(): void
    {
        $this->assertUnrecognized('/api/panels/1?include=curator&fields[curators]=stats.secret', 'stats.secret');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aDottedMemberOnAnAttributeWithoutSelectableKeysIsThe400(): void
    {
        $this->assertUnrecognized('/api/panels/1?fields[panels]=settings.theme', 'settings.theme');
        $this->assertUnrecognized('/api/panels/1?fields[panels]=name.first', 'name.first');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aDottedMemberOnARelationshipIsThe400(): void
    {
        $this->assertUnrecognized('/api/panels/1?fields[panels]=curator.name', 'curator.name');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aDeeperPathOrAnEmptyKeyIsThe400(): void
    {
        $this->assertUnrecognized('/api/panels/1?fields[panels]=groups.rows.a', 'groups.rows.a');
        $this->assertUnrecognized('/api/panels/1?fields[panels]=values.', 'values.');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aRejectedKeyOnAWriteIsThe400AndStoresNothing(): void
    {
        $response = $this->json('PATCH', '/api/panels/1?fields[panels]=values.Bad', ['data' => [
            'type' => 'panels',
            'id' => '1',
            'attributes' => ['name' => 'Changed'],
        ]], ['Accept' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE]);

        $response->assertStatus(400);
        self::assertSame('FIELDSET_MEMBER_UNRECOGNIZED', $response->json('errors.0.code'));

        $this->detachPersistedState();
        self::assertSame('Seed', $this->attributesOf($this->fetch('/api/panels/1'))['name'] ?? null);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:atomic')]
    #[Group('spec:errors')]
    public function anAtomicBatchWithARejectedKeyIsThe400(): void
    {
        $response = $this->atomic([[
            'op' => 'add',
            'data' => ['type' => 'panels', 'attributes' => ['name' => 'New']],
        ]], 'fields[panels]=values.Bad');

        $response->assertStatus(400);
        self::assertSame('FIELDSET_MEMBER_UNRECOGNIZED', $response->json('errors.0.code'));
        self::assertSame(['parameter' => 'fields'], $response->json('errors.0.source'));
        self::assertCount(1, (array) $this->fetch('/api/panels')->json('data'));
    }

    private function assertUnrecognized(string $uri, string $member): void
    {
        $response = $this->fetch($uri);

        $response->assertStatus(400);
        self::assertSame('FIELDSET_MEMBER_UNRECOGNIZED', $response->json('errors.0.code'));
        self::assertSame(['parameter' => 'fields'], $response->json('errors.0.source'));
        $detail = $response->json('errors.0.detail');
        self::assertIsString($detail);
        self::assertStringContainsString($member, $detail);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, mixed>
     */
    private function attributesOf(TestResponse $response): array
    {
        $attributes = $response->json('data.attributes') ?? [];
        self::assertIsArray($attributes, (string) $response->getContent());

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, mixed>
     */
    private function jsonApiOf(TestResponse $response): array
    {
        $jsonApi = $response->json('jsonapi') ?? [];
        self::assertIsArray($jsonApi);

        /** @var array<string, mixed> $jsonApi */
        return $jsonApi;
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function fetch(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function atomic(array $operations, string $query): TestResponse
    {
        return $this->call('POST', '/api/operations?' . $query, [], [], [], [
            'HTTP_ACCEPT' => self::ATOMIC_MEDIA_TYPE,
            'CONTENT_TYPE' => self::ATOMIC_MEDIA_TYPE,
        ], (string) \json_encode(['atomic:operations' => $operations]));
    }
}
