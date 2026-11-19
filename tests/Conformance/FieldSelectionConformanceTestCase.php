<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Schema\Profile\FieldSelectionProfile;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
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
    public function aRelatedEndpointNarrowingNothingAdvertisesNoProfile(): void
    {
        $response = $this->fetch('/api/panels/1/curator?fields[curators]=name');

        $response->assertOk();
        self::assertSame(['name' => 'Ada'], $this->attributesOf($response));
        self::assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
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
