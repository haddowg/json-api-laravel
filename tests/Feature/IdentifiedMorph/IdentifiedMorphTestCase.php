<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\IdentifiedMorph;

use haddowg\JsonApiLaravel\DataProvider\RelatedIncludeBatcher;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph\HidesUmbrellaPolicy;
use haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph\IdentifiedMorphServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * A `MorphTo` identified by the type and id columns its parent stores: `campaigns`
 * target an advertiser or an agency. Advertisers are guarded, by a global scope or a scoping
 * provider that hides advertiser 3, and agencies are not. Agency linkage renders from the columns
 * alone; advertiser linkage, an include and the related endpoint load each type's targets in one
 * read through that type's provider. See {@see IdentifiedMorphServiceProvider} for the seed.
 *
 * @internal
 */
abstract class IdentifiedMorphTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * `eloquent` or `memory`.
     */
    abstract protected function store(): string;

    /**
     * The SQL `$read` runs, or `null` for a store that runs none.
     *
     * @return list<string>|null
     */
    abstract protected function queries(\Closure $read): ?array;

    /**
     * The class the store's agency rows are.
     *
     * @return class-string
     */
    abstract protected function agencyClass(): string;

    /**
     * Stores campaign `$id` with a target of stored type `$type`.
     */
    abstract protected function addCampaign(int $id, string $type, int $target): void;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('identified.store', $this->store());

        return [JsonApiServiceProvider::class, IdentifiedMorphServiceProvider::class];
    }

    #[Test]
    public function anUnguardedTypeRendersItsPairAndAGuardedTypeLoadsInOneRead(): void
    {
        $queries = $this->queries(fn() => $this->read('/api/campaigns'));
        $campaigns = $this->read('/api/campaigns');

        self::assertSame([
            ['type' => 'advertisers', 'id' => '1'],
            ['type' => 'agencies', 'id' => '1'],
            ['type' => 'advertisers', 'id' => '2'],
            null,
            // Advertisers are guarded, so the hidden one renders as none without an include.
            null,
            ['type' => 'agencies', 'id' => '2'],
            ['type' => 'advertisers', 'id' => '1'],
            ['type' => 'agencies', 'id' => '1'],
            ['type' => 'advertisers', 'id' => '2'],
            ['type' => 'agencies', 'id' => '2'],
        ], $this->linkage($campaigns));
        self::assertNull($campaigns->json('included'));

        if ($queries !== null) {
            self::assertCount(2, $queries, \implode("\n", $queries));
            self::assertSame(1, $this->reads($queries, 'im_advertisers'));
            self::assertSame(0, $this->reads($queries, 'im_agencies'));
        }
    }

    #[Test]
    public function aViewPolicyGuardsATypeToo(): void
    {
        Gate::policy($this->agencyClass(), HidesUmbrellaPolicy::class);

        $queries = $this->queries(fn() => $this->read('/api/campaigns'));
        $linkage = $this->linkage($this->read('/api/campaigns'));

        self::assertSame(['type' => 'agencies', 'id' => '1'], $linkage[1]);
        self::assertNull($linkage[5]);
        self::assertNull($linkage[9]);

        if ($queries !== null) {
            self::assertSame(1, $this->reads($queries, 'im_agencies'));
        }
    }

    #[Test]
    public function anIncludeLoadsEachTypeInOneReadAndLeavesOutAHiddenTarget(): void
    {
        $queries = $this->queries(fn() => $this->read('/api/campaigns?include=target'));
        $campaigns = $this->read('/api/campaigns?include=target');

        $linkage = $this->linkage($campaigns);
        self::assertNull($linkage[4]);
        self::assertSame(['type' => 'advertisers', 'id' => '1'], $linkage[0]);
        self::assertSame(['type' => 'agencies', 'id' => '2'], $linkage[9]);
        self::assertSame(['advertisers:1', 'advertisers:2', 'agencies:1', 'agencies:2'], $this->included($campaigns));
        $campaigns->assertJsonPath('included.0.attributes.name', 'Acme');

        if ($queries !== null) {
            // The campaigns, one read per target type, then each type's owners for the included
            // targets' own linkage.
            self::assertCount(5, $queries, \implode("\n", $queries));
            self::assertSame(1, $this->reads($queries, 'im_advertisers'));
            self::assertSame(1, $this->reads($queries, 'im_agencies'));
            self::assertSame(2, $this->reads($queries, 'im_people'));
        }
    }

    #[Test]
    public function anIncludeContinuesBeneathTheTarget(): void
    {
        $queries = $this->queries(fn() => $this->read('/api/campaigns?include=target.owner'));
        $campaigns = $this->read('/api/campaigns?include=target.owner');

        self::assertSame(['advertisers:1', 'advertisers:2', 'agencies:1', 'agencies:2', 'people:1', 'people:2'], $this->included($campaigns));

        if ($queries !== null) {
            self::assertCount(5, $queries, \implode("\n", $queries));
            self::assertSame(2, $this->reads($queries, 'im_people'));
        }
    }

    #[Test]
    public function anIncludeLoadsTheTargetWithBatchingOff(): void
    {
        $this->app?->make(RelatedIncludeBatcher::class)->disable();

        $campaigns = $this->read('/api/campaigns?include=target.owner');

        self::assertNull($this->linkage($campaigns)[4]);
        self::assertSame(['advertisers:1', 'advertisers:2', 'agencies:1', 'agencies:2', 'people:1', 'people:2'], $this->included($campaigns));
    }

    #[Test]
    public function aSingleReadIncludesItsTarget(): void
    {
        $campaign = $this->read('/api/campaigns/2?include=target');

        $campaign->assertJsonPath('data.relationships.target.data', ['type' => 'agencies', 'id' => '1']);
        self::assertSame(['agencies:1'], $this->included($campaign));
        $campaign->assertJsonPath('included.0.attributes.name', 'Initech');

        $hidden = $this->read('/api/campaigns/5?include=target');
        $hidden->assertJsonPath('data.relationships.target.data', null);
        $hidden->assertJsonPath('included', []);
    }

    #[Test]
    public function theRelatedEndpointReadsTheTargetThroughItsType(): void
    {
        $advertiser = $this->read('/api/campaigns/1/target');
        $advertiser->assertJsonPath('data.type', 'advertisers');
        $advertiser->assertJsonPath('data.id', '1');
        $advertiser->assertJsonPath('data.attributes.name', 'Acme');

        $agency = $this->read('/api/campaigns/6/target');
        $agency->assertJsonPath('data.type', 'agencies');
        $agency->assertJsonPath('data.attributes.name', 'Umbrella');

        $owned = $this->read('/api/campaigns/3/target?include=owner');
        $owned->assertJsonPath('data.relationships.owner.data', ['type' => 'people', 'id' => '2']);
        self::assertSame(['people:2'], $this->included($owned));
    }

    #[Test]
    public function theRelatedEndpointRendersNullForAnEmptyOrHiddenTarget(): void
    {
        $this->read('/api/campaigns/4/target')->assertJsonPath('data', null);
        $this->read('/api/campaigns/5/target')->assertJsonPath('data', null);
    }

    #[Test]
    public function theRelationshipEndpointFollowsTheSameRule(): void
    {
        $unguarded = $this->queries(fn() => $this->read('/api/campaigns/2/relationships/target'));
        $guarded = $this->queries(fn() => $this->read('/api/campaigns/9/relationships/target'));

        $this->read('/api/campaigns/2/relationships/target')->assertJsonPath('data', ['type' => 'agencies', 'id' => '1']);
        $this->read('/api/campaigns/9/relationships/target')->assertJsonPath('data', ['type' => 'advertisers', 'id' => '2']);
        $this->read('/api/campaigns/5/relationships/target')->assertJsonPath('data', null);
        $this->read('/api/campaigns/4/relationships/target')->assertJsonPath('data', null);

        if ($unguarded !== null && $guarded !== null) {
            self::assertCount(1, $unguarded, \implode("\n", $unguarded));
            self::assertCount(2, $guarded, \implode("\n", $guarded));
            self::assertSame(1, $this->reads($guarded, 'im_advertisers'));
        }
    }

    #[Test]
    public function aStoredTypeTheRelationDoesNotDeclareIsAServerError(): void
    {
        $this->addCampaign(11, 'people', 1);
        // Debug mode puts the exception in the error's meta.
        \config(['app.debug' => true]);

        $response = $this->get('/api/campaigns/11', ['Accept' => self::MEDIA_TYPE]);

        $response->assertStatus(500);
        $response->assertJsonPath('errors.0.status', '500');
        $response->assertJsonPath('errors.0.meta.exception', \LogicException::class);
        $file = $response->json('errors.0.meta.file');
        self::assertIsString($file);
        self::assertStringEndsWith('MorphTo.php', $file);
    }

    /**
     * @return TestResponse<Response>
     */
    protected function read(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE])->assertOk();
    }

    /**
     * How many of `$queries` read `$table`.
     *
     * @param list<string> $queries
     */
    private function reads(array $queries, string $table): int
    {
        return \count(\array_filter($queries, static fn(string $query): bool => \str_contains($query, 'from "' . $table . '"')));
    }

    /**
     * Each primary resource's `target` linkage, in document order.
     *
     * @param TestResponse<Response> $response
     *
     * @return list<mixed>
     */
    private function linkage(TestResponse $response): array
    {
        $data = $response->json('data');
        self::assertIsArray($data);

        return \array_values(\array_map(static fn(mixed $resource): mixed => \is_array($resource) && \is_array($resource['relationships'] ?? null) && \is_array($resource['relationships']['target'] ?? null)
            ? $resource['relationships']['target']['data'] ?? null
            : 'missing', $data));
    }

    /**
     * The `type:id` of each included resource, sorted.
     *
     * @param TestResponse<Response> $response
     *
     * @return list<string>
     */
    private function included(TestResponse $response): array
    {
        $included = $response->json('included');
        self::assertIsArray($included);
        $keys = \array_map(static fn(mixed $resource): string => \is_array($resource) && \is_string($resource['type'] ?? null) && \is_string($resource['id'] ?? null)
            ? $resource['type'] . ':' . $resource['id']
            : '', $included);
        \sort($keys);

        return $keys;
    }
}
