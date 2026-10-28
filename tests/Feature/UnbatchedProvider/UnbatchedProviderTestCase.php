<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\UnbatchedProvider;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\UnbatchedProvider\UnbatchedProviderServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * `advertisers` is served by a provider that never batches a relation. Its relations still
 * render what they hold, whether included or only as linkage, and a read leaves the stored
 * rows as they were for every later request.
 *
 * @internal
 */
abstract class UnbatchedProviderTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string RELATIONSHIP_QUERIES = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/relationship-queries/"';

    /**
     * `eloquent` or `memory`.
     */
    abstract protected function store(): string;

    /**
     * The stored owner id of advertiser `$id`, or null when it has none.
     */
    abstract protected function storedOwnerOf(string $id): ?string;

    /**
     * The stored campaign ids of advertiser `$id`.
     *
     * @return list<string>
     */
    abstract protected function storedCampaignsOf(string $id): array;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('unbatched.store', $this->store());

        return [JsonApiServiceProvider::class, UnbatchedProviderServiceProvider::class];
    }

    #[Test]
    public function aCollectionReadRendersEachOwnerAndLeavesThemStored(): void
    {
        $advertisers = $this->read('/api/advertisers');

        $advertisers->assertJsonPath('data.0.relationships.owner.data', ['type' => 'owners', 'id' => '1']);
        $advertisers->assertJsonPath('data.1.relationships.owner.data', ['type' => 'owners', 'id' => '2']);
        $this->assertOwnersStored();

        $this->read('/api/advertisers/2')->assertJsonPath('data.relationships.owner.data', ['type' => 'owners', 'id' => '2']);
    }

    #[Test]
    public function anIncludedOwnerRendersAndStaysStored(): void
    {
        $advertisers = $this->read('/api/advertisers?include=owner');

        $advertisers->assertJsonPath('data.0.relationships.owner.data', ['type' => 'owners', 'id' => '1']);
        $advertisers->assertJsonPath('data.1.relationships.owner.data', ['type' => 'owners', 'id' => '2']);
        self::assertSame(['owners:1', 'owners:2'], $this->included($advertisers));
        $this->assertOwnersStored();

        $this->read('/api/advertisers/2?include=owner')
            ->assertJsonPath('data.relationships.owner.data', ['type' => 'owners', 'id' => '2'])
            ->assertJsonPath('included.0.attributes.name', 'Grace');
    }

    #[Test]
    public function anIncludedToManyRendersAndStaysStored(): void
    {
        $advertisers = $this->read('/api/advertisers?include=campaigns');

        $advertisers->assertJsonPath('data.0.relationships.campaigns.data', [['type' => 'campaigns', 'id' => '1'], ['type' => 'campaigns', 'id' => '2']]);
        $advertisers->assertJsonPath('data.1.relationships.campaigns.data', [['type' => 'campaigns', 'id' => '3']]);
        self::assertSame(['campaigns:1', 'campaigns:2', 'campaigns:3'], $this->included($advertisers));
        self::assertSame(['1', '2'], $this->storedCampaignsOf('1'));
        self::assertSame(['3'], $this->storedCampaignsOf('2'));

        $this->read('/api/advertisers/2?include=campaigns')
            ->assertJsonPath('data.relationships.campaigns.data', [['type' => 'campaigns', 'id' => '3']]);
    }

    #[Test]
    public function aLaterReadStillSeesTheOwnerAnIncludeLoaded(): void
    {
        $this->read('/api/advertisers?include=owner,campaigns');

        $this->read('/api/advertisers/2')->assertJsonPath('data.relationships.owner.data', ['type' => 'owners', 'id' => '2']);
        $this->read('/api/advertisers/1/campaigns')->assertJsonPath('data.1.id', '2');
    }

    #[Test]
    public function aWindowedToManyIsReadPerParent(): void
    {
        $this->read('/api/advertisers?include=campaigns&relatedQuery[campaigns][sort]=title', self::RELATIONSHIP_QUERIES)
            ->assertJsonPath('data.0.relationships.campaigns.data', [['type' => 'campaigns', 'id' => '1'], ['type' => 'campaigns', 'id' => '2']])
            ->assertJsonPath('data.1.relationships.campaigns.data', [['type' => 'campaigns', 'id' => '3']]);
        self::assertSame(['1', '2'], $this->storedCampaignsOf('1'));
    }

    #[Test]
    public function aFilterExcludingAnOwnerHidesItForThatReadOnly(): void
    {
        $advertisers = $this->read('/api/advertisers?include=owner&relatedQuery[owner][filter][name]=Ada', self::RELATIONSHIP_QUERIES);

        $advertisers->assertJsonPath('data.0.relationships.owner.data', ['type' => 'owners', 'id' => '1']);
        $advertisers->assertJsonPath('data.1.relationships.owner.data', null);
        self::assertSame(['owners:1'], $this->included($advertisers));
        $this->assertOwnersStored();

        $this->read('/api/advertisers/2')->assertJsonPath('data.relationships.owner.data', ['type' => 'owners', 'id' => '2']);
    }

    #[Test]
    public function aSingleReadRendersItsOwnerAndLeavesItStored(): void
    {
        $this->read('/api/advertisers/2')->assertJsonPath('data.relationships.owner.data', ['type' => 'owners', 'id' => '2']);
        $this->assertOwnersStored();

        $this->read('/api/advertisers/2/owner')->assertJsonPath('data.id', '2');
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function read(string $uri, string $accept = self::MEDIA_TYPE): TestResponse
    {
        return $this->get($uri, ['Accept' => $accept])->assertOk();
    }

    private function assertOwnersStored(): void
    {
        self::assertSame('1', $this->storedOwnerOf('1'));
        self::assertSame('2', $this->storedOwnerOf('2'));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    private function included(TestResponse $response): array
    {
        $included = $response->json('included');
        self::assertIsArray($included);
        $keys = \array_map(
            static fn(mixed $resource): string => \is_array($resource) && \is_string($resource['type'] ?? null) && \is_string($resource['id'] ?? null)
                ? $resource['type'] . ':' . $resource['id']
                : '',
            $included,
        );
        \sort($keys);

        return $keys;
    }
}
