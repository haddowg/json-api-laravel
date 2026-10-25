<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ScopedIncludes;

use haddowg\JsonApiLaravel\DataProvider\RelatedIncludeBatcher;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes\Coin;
use haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes\Mark;
use haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes\ScopedIncludesServiceProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * Rows a type's read scope hides must never reach a client through another type's document:
 * not as included resources, not as ids in relationship linkage, not in a `meta.total`.
 *
 * `coins` and `marks` each hide some rows (coins 2 and 4, marks 2 and 3), either through an
 * application provider that owns the type or through an Eloquent global scope. Every read that
 * renders them from a `vaults` or `coins` parent (`?include`, nested includes, linkage rendered
 * without `?include`, `?withCount`, the Relationship Queries window, the relationship endpoint)
 * must show exactly what the related endpoint shows. Each concrete arm picks the store, the
 * scope and the server the types are registered on.
 *
 * Visible membership: vault 1 → coins 1, 3; vault 2 → coin 5; coin 1 → mark 1; coin 3 → none;
 * coin 5 → mark 4; chest 1 holds coin 1 and mark 1 (and hidden coin 2 and mark 3). Vault 1
 * features coin 1 and spotlights hidden coin 2; vault 2 features hidden coin 4 and spotlights
 * mark 1, so those hidden to-one targets render as empty.
 *
 * A polymorphic relation spanning types with their own providers has no single scoped read,
 * so under a provider scope rendering its members is refused (a 500) rather than shown.
 *
 * @internal
 */
abstract class ScopedIncludesTestCase extends Orchestra
{
    use WritesThroughScopedTypes;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string RELATIONSHIP_QUERIES_PROFILE = 'https://haddowg.github.io/json-api/profiles/relationship-queries/';

    private const string COUNTABLE_PROFILE = 'https://haddowg.github.io/json-api/profiles/countable/';

    private const array HIDDEN = [
        'coins' => ['2', '4'],
        'marks' => ['2', '3'],
    ];

    /**
     * `partner` (the types live on that named server only) or `default`.
     */
    abstract protected function server(): string;

    /**
     * `eloquent` or `memory`.
     */
    abstract protected function store(): string;

    /**
     * `provider` (an application provider per scoped type) or `global` (an Eloquent global scope).
     */
    abstract protected function scope(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Set here rather than in defineEnvironment(): the fixture provider reads these while
        // registering, which testbench runs before defineEnvironment().
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('scoped_includes.server', $this->server());
        $config->set('scoped_includes.store', $this->store());
        $config->set('scoped_includes.scope', $this->scope());

        return [JsonApiServiceProvider::class, ScopedIncludesServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'partner' => ['prefix' => 'partner-api', 'middleware' => [], 'domain' => null],
        ]);
        $config->set('jsonapi.atomic_operations.enabled', true);
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        if ($this->store() !== 'eloquent') {
            return;
        }

        Schema::create('si_vaults', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('label');
            $table->unsignedInteger('featured_coin_id')->nullable();
            $table->unsignedInteger('spotlight_id')->nullable();
            $table->string('spotlight_type')->nullable();
        });
        Schema::create('si_coins', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('vault_id')->nullable();
            $table->string('label');
            $table->boolean('hidden')->default(false);
        });
        Schema::create('si_marks', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('label');
            $table->boolean('hidden')->default(false);
        });
        Schema::create('si_coin_mark', static function (Blueprint $table): void {
            $table->unsignedInteger('coin_id');
            $table->unsignedInteger('mark_id');
            $table->integer('position')->default(0);
        });
        Schema::create('si_chests', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('label');
        });
        Schema::create('si_chest_items', static function (Blueprint $table): void {
            $table->unsignedInteger('chest_id');
            $table->unsignedInteger('item_id');
            $table->string('item_type');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->store() !== 'eloquent') {
            return;
        }

        if ($this->scope() === 'global') {
            Coin::addGlobalScope('visible', static fn(Builder $query) => $query->where('si_coins.hidden', false));
            Mark::addGlobalScope('visible', static fn(Builder $query) => $query->where('si_marks.hidden', false));
        }

        DB::table('si_vaults')->insert([
            ['id' => 1, 'label' => 'North', 'featured_coin_id' => 1, 'spotlight_id' => 2, 'spotlight_type' => Coin::class],
            ['id' => 2, 'label' => 'South', 'featured_coin_id' => 4, 'spotlight_id' => 1, 'spotlight_type' => Mark::class],
        ]);
        DB::table('si_coins')->insert([
            ['id' => 1, 'vault_id' => 1, 'label' => 'alpha', 'hidden' => false],
            ['id' => 2, 'vault_id' => 1, 'label' => 'bravo', 'hidden' => true],
            ['id' => 3, 'vault_id' => 1, 'label' => 'charlie', 'hidden' => false],
            ['id' => 4, 'vault_id' => 2, 'label' => 'delta', 'hidden' => true],
            ['id' => 5, 'vault_id' => 2, 'label' => 'echo', 'hidden' => false],
        ]);
        DB::table('si_marks')->insert([
            ['id' => 1, 'label' => 'm-one', 'hidden' => false],
            ['id' => 2, 'label' => 'm-two', 'hidden' => true],
            ['id' => 3, 'label' => 'm-three', 'hidden' => true],
            ['id' => 4, 'label' => 'm-four', 'hidden' => false],
        ]);
        DB::table('si_coin_mark')->insert([
            ['coin_id' => 1, 'mark_id' => 1, 'position' => 1],
            ['coin_id' => 1, 'mark_id' => 2, 'position' => 2],
            ['coin_id' => 2, 'mark_id' => 1, 'position' => 1],
            ['coin_id' => 3, 'mark_id' => 3, 'position' => 1],
            ['coin_id' => 4, 'mark_id' => 4, 'position' => 1],
            ['coin_id' => 5, 'mark_id' => 4, 'position' => 1],
        ]);
        DB::table('si_chests')->insert(['id' => 1, 'label' => 'Oak']);
        DB::table('si_chest_items')->insert([
            ['chest_id' => 1, 'item_id' => 1, 'item_type' => Coin::class],
            ['chest_id' => 1, 'item_id' => 2, 'item_type' => Coin::class],
            ['chest_id' => 1, 'item_id' => 1, 'item_type' => Mark::class],
            ['chest_id' => 1, 'item_id' => 3, 'item_type' => Mark::class],
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // The global scopes are static model state; drop them so no other test inherits them.
        Model::clearBootedModels();
    }

    #[Test]
    public function theRelatedEndpointShowsOnlyVisibleRows(): void
    {
        self::assertSame(['1', '3'], $this->ids($this->read('/vaults/1/coins'), 'data'));
        self::assertSame(['1'], $this->ids($this->read('/coins/1/marks'), 'data'));
    }

    #[Test]
    public function aCollectionIncludeNeverExposesHiddenRows(): void
    {
        $response = $this->read('/vaults?include=coins');

        $this->assertNoHiddenRows($response);
        self::assertSame(['1', '3'], $this->ids($response, 'data.0.relationships.coins.data'));
        self::assertSame(['5'], $this->ids($response, 'data.1.relationships.coins.data'));
        self::assertSame(['coins:1', 'coins:3', 'coins:5'], $this->included($response, 'coins'));
    }

    #[Test]
    public function aSingleResourceIncludeNeverExposesHiddenRows(): void
    {
        $response = $this->read('/vaults/1?include=coins');

        $this->assertNoHiddenRows($response);
        self::assertSame(['1', '3'], $this->ids($response, 'data.relationships.coins.data'));
        self::assertSame(['coins:1', 'coins:3'], $this->included($response, 'coins'));
    }

    #[Test]
    public function aNestedIncludeNeverExposesHiddenRows(): void
    {
        $response = $this->read('/vaults?include=coins.marks');

        $this->assertNoHiddenRows($response);
        self::assertSame(['marks:1', 'marks:4'], $this->included($response, 'marks'));
        self::assertSame(['1'], $this->ids($response, $this->includedPath($response, 'coins', '1') . '.relationships.marks.data'));
        self::assertSame([], $this->ids($response, $this->includedPath($response, 'coins', '3') . '.relationships.marks.data'));
    }

    #[Test]
    public function linkageRenderedWithoutAnIncludeNeverExposesHiddenRows(): void
    {
        // `marks` renders its linkage on every read, so neither of these includes it.
        $nested = $this->read('/vaults/1?include=coins');
        $this->assertNoHiddenRows($nested);
        self::assertSame(['1'], $this->ids($nested, $this->includedPath($nested, 'coins', '1') . '.relationships.marks.data'));

        $primary = $this->read('/coins');
        $this->assertNoHiddenRows($primary);
        self::assertSame(['1'], $this->ids($primary, 'data.0.relationships.marks.data'));
    }

    #[Test]
    public function anIncludeWithBatchingDisabledNeverExposesHiddenRows(): void
    {
        $this->disableBatching();

        $response = $this->read('/vaults?include=coins.marks');

        $this->assertNoHiddenRows($response);
        self::assertSame(['coins:1', 'coins:3', 'coins:5'], $this->included($response, 'coins'));
        self::assertSame(['marks:1', 'marks:4'], $this->included($response, 'marks'));
    }

    #[Test]
    public function anIncludeOnTheRelatedEndpointNeverExposesHiddenRows(): void
    {
        $response = $this->read('/vaults/1/coins?include=marks');

        $this->assertNoHiddenRows($response);
        self::assertSame(['marks:1'], $this->included($response, 'marks'));
    }

    #[Test]
    public function aRelationshipCountCountsOnlyVisibleRows(): void
    {
        $response = $this->read('/vaults?withCount=coins', self::MEDIA_TYPE . ';profile="' . self::COUNTABLE_PROFILE . '"');

        $response->assertOk();
        self::assertSame(2, $response->json('data.0.relationships.coins.meta.total'));
        self::assertSame(1, $response->json('data.1.relationships.coins.meta.total'));
    }

    #[Test]
    public function aRelationshipQueriesWindowNeverExposesHiddenRows(): void
    {
        $response = $this->read(
            '/vaults?include=coins&relatedQuery[coins][sort]=-label',
            self::MEDIA_TYPE . ';profile="' . self::RELATIONSHIP_QUERIES_PROFILE . '"',
        );

        $this->assertNoHiddenRows($response);
        self::assertSame(['3', '1'], $this->ids($response, 'data.0.relationships.coins.data'));
        self::assertSame(['5'], $this->ids($response, 'data.1.relationships.coins.data'));
    }

    #[Test]
    public function aFilteredWindowAndItsCountAgreeWithTheRelatedEndpoint(): void
    {
        // `label` contains "r" matches bravo and charlie in vault 1, but bravo is hidden.
        $response = $this->read(
            '/vaults?include=coins&withCount=coins&relatedQuery[coins][filter][label]=r',
            self::MEDIA_TYPE . ';profile="' . self::RELATIONSHIP_QUERIES_PROFILE . ' ' . self::COUNTABLE_PROFILE . '"',
        );

        $this->assertNoHiddenRows($response);
        self::assertSame(['3'], $this->ids($response, 'data.0.relationships.coins.data'));
        self::assertSame(1, $response->json('data.0.relationships.coins.meta.total'));
        self::assertSame([], $this->ids($response, 'data.1.relationships.coins.data'));
        self::assertSame(0, $response->json('data.1.relationships.coins.meta.total'));

        $related = $this->read('/vaults/1/coins?filter[label]=r&withCount=_self_', self::MEDIA_TYPE . ';profile="' . self::COUNTABLE_PROFILE . '"');
        self::assertSame(['3'], $this->ids($related, 'data'));
        self::assertSame(1, $related->json('meta.total'));
    }

    #[Test]
    public function theRelationshipEndpointShowsOnlyVisibleRows(): void
    {
        self::assertSame(['1', '3'], $this->ids($this->read('/vaults/1/relationships/coins'), 'data'));
        self::assertSame(['1'], $this->ids($this->read('/coins/1/relationships/marks'), 'data'));
        self::assertSame(['3', '1'], $this->ids($this->read('/vaults/1/relationships/coins?sort=-label'), 'data'));
    }

    #[Test]
    public function aBatchedToOneAboveAScopedRelationStillScopesIt(): void
    {
        // `featuredCoin` is a to-one, loaded off the vault; the `marks` below it are scoped.
        $response = $this->read('/vaults/1?include=featuredCoin.marks');

        $this->assertNoHiddenRows($response);
        self::assertSame(['marks:1'], $this->included($response, 'marks'));
    }

    #[Test]
    public function anUnbatchedToOneAboveAScopedRelationStillScopesIt(): void
    {
        $this->disableBatching();

        $response = $this->read('/vaults?include=featuredCoin.marks');

        $this->assertNoHiddenRows($response);
        self::assertSame(['marks:1'], $this->included($response, 'marks'));
    }

    #[Test]
    public function aPolymorphicIncludeOverScopedTypesIsRefusedOrScoped(): void
    {
        if ($this->scope() === 'provider') {
            $this->read('/chests/1?include=holdings', expectOk: false)->assertStatus(500);
            $this->read('/chests?include=holdings', expectOk: false)->assertStatus(500);

            return;
        }

        $response = $this->read('/chests/1?include=holdings');

        $this->assertNoHiddenRows($response);
        self::assertSame(['coins:1'], $this->included($response, 'coins'));
        self::assertSame(['marks:1'], $this->included($response, 'marks'));
    }

    #[Test]
    public function polymorphicLinkageOverScopedTypesIsRefusedOrScoped(): void
    {
        $plain = $this->read('/chests/1', expectOk: false);
        $relationship = $this->read('/chests/1/relationships/holdings', expectOk: false);
        $related = $this->read('/chests/1/holdings', expectOk: false);

        if ($this->scope() === 'provider') {
            $relationship->assertStatus(500);
            $related->assertStatus(500);

            // The in-memory witness renders a to-many's linkage on every read; Eloquent only
            // once the relation is loaded, so its plain read renders links alone.
            if ($this->store() === 'memory') {
                $plain->assertStatus(500);
            } else {
                $this->assertNoHiddenRows($plain);
                self::assertNull($plain->json('data.relationships.holdings.data'));
            }

            return;
        }

        $this->assertNoHiddenRows($plain);
        $this->assertNoHiddenRows($relationship);
        $this->assertNoHiddenRows($related);
        self::assertSame(['1', '1'], $this->ids($related, 'data'));
    }

    #[Test]
    public function aPolymorphicCountOverScopedTypesIsRefusedOrScoped(): void
    {
        $response = $this->read('/chests?withCount=holdings', self::MEDIA_TYPE . ';profile="' . self::COUNTABLE_PROFILE . '"', expectOk: false);

        if ($this->scope() === 'provider') {
            $response->assertStatus(500);

            return;
        }

        // Eloquent has no count for a set composed by `extractUsing`, so the total may be absent;
        // when present it counts the visible members only.
        $response->assertOk();
        self::assertContains($response->json('data.0.relationships.holdings.meta.total'), [null, 2]);
    }

    #[Test]
    public function toOneLinkageNeverExposesAHiddenTarget(): void
    {
        $response = $this->read('/vaults');

        $this->assertNoHiddenRows($response);
        self::assertSame('1', $response->json('data.0.relationships.featuredCoin.data.id'));
        self::assertNull($response->json('data.1.relationships.featuredCoin.data'));
    }

    #[Test]
    public function aToOneIncludeNeverExposesAHiddenTarget(): void
    {
        $response = $this->read('/vaults?include=featuredCoin');

        $this->assertNoHiddenRows($response);
        self::assertSame(['coins:1'], $this->included($response, 'coins'));
        self::assertNull($response->json('data.1.relationships.featuredCoin.data'));
    }

    #[Test]
    public function theToOneRelationshipEndpointNeverExposesAHiddenTarget(): void
    {
        self::assertSame('1', $this->read('/vaults/1/relationships/featuredCoin')->json('data.id'));

        $hidden = $this->read('/vaults/2/relationships/featuredCoin');
        $this->assertNoHiddenRows($hidden);
        self::assertNull($hidden->json('data'));
    }

    #[Test]
    public function theToOneRelatedEndpointNeverExposesAHiddenTarget(): void
    {
        self::assertSame('1', $this->read('/vaults/1/featuredCoin')->json('data.id'));

        $hidden = $this->read('/vaults/2/featuredCoin?include=marks');
        $this->assertNoHiddenRows($hidden);
        self::assertNull($hidden->json('data'));
    }

    #[Test]
    public function aFlattenedAttributeNeverReadsAHiddenTarget(): void
    {
        $response = $this->read('/vaults');

        self::assertSame('alpha', $response->json('data.0.attributes.featuredLabel'));
        self::assertNull($response->json('data.1.attributes.featuredLabel'));
    }

    #[Test]
    public function aFlattenedAttributeNeverWritesAHiddenTarget(): void
    {
        $hidden = $this->write('/vaults/2', ['data' => ['type' => 'vaults', 'id' => '2', 'attributes' => ['featuredLabel' => 'renamed']]]);
        $hidden->assertStatus(422);
        self::assertSame('RELATED_ATTRIBUTE_OWNER_MISSING', $hidden->json('errors.0.code'));

        $visible = $this->write('/vaults/1', ['data' => ['type' => 'vaults', 'id' => '1', 'attributes' => ['featuredLabel' => 'renamed']]]);
        $visible->assertOk();
        self::assertSame('renamed', $visible->json('data.attributes.featuredLabel'));
        self::assertSame('renamed', $this->read('/vaults/1')->json('data.attributes.featuredLabel'));
        self::assertSame('renamed', $this->read('/coins/1')->json('data.attributes.label'));
    }

    #[Test]
    public function aPolymorphicToOneNeverExposesAHiddenTarget(): void
    {
        $response = $this->read('/vaults?include=spotlight');

        $this->assertNoHiddenRows($response);
        self::assertNull($response->json('data.0.relationships.spotlight.data'));
        self::assertSame(['type' => 'marks', 'id' => '1'], $response->json('data.1.relationships.spotlight.data'));
        self::assertSame(['marks:1'], $this->included($response, 'marks'));
        self::assertSame([], $this->included($response, 'coins'));

        self::assertNull($this->read('/vaults/1/spotlight')->json('data'));
        self::assertNull($this->read('/vaults/1/relationships/spotlight')->json('data'));
        self::assertSame('1', $this->read('/vaults/2/spotlight')->json('data.id'));
    }

    #[Test]
    public function aHiddenRowIsNotFoundByItsOwnEndpoint(): void
    {
        $this->read('/coins/2', expectOk: false)->assertNotFound();
        $this->read('/marks/3', expectOk: false)->assertNotFound();
    }

    private function disableBatching(): void
    {
        $batcher = app(RelatedIncludeBatcher::class);
        self::assertInstanceOf(RelatedIncludeBatcher::class, $batcher);
        $batcher->disable();
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function read(string $path, string $accept = self::MEDIA_TYPE, bool $expectOk = true): TestResponse
    {
        $prefix = $this->server() === 'partner' ? '/partner-api' : '/api';
        $response = $this->get($prefix . $path, ['Accept' => $accept]);

        if ($expectOk) {
            $response->assertOk();
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function write(string $path, array $document): TestResponse
    {
        $prefix = $this->server() === 'partner' ? '/partner-api' : '/api';

        return $this->call('PATCH', $prefix . $path, [], [], [], [
            'CONTENT_TYPE' => self::MEDIA_TYPE,
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
        ], \json_encode($document, \JSON_THROW_ON_ERROR));
    }

    /**
     * Fails if any hidden row appears anywhere in the document: as primary data, as an included
     * resource, or as an identifier in any relationship's linkage.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertNoHiddenRows(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertNothingHidden($response);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertNothingHidden(TestResponse $response): void
    {
        $seen = [];
        $collect = static function (mixed $node) use (&$collect, &$seen): void {
            if (!\is_array($node)) {
                return;
            }
            if (isset($node['type'], $node['id']) && \is_string($node['type']) && \is_string($node['id'])) {
                $seen[] = $node['type'] . ':' . $node['id'];
            }
            foreach ($node as $child) {
                $collect($child);
            }
        };
        $collect($response->json());

        foreach (self::HIDDEN as $type => $ids) {
            foreach ($ids as $id) {
                self::assertNotContains($type . ':' . $id, $seen, \sprintf('Hidden %s %s leaked into the document.', $type, $id));
            }
        }
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    private function ids(TestResponse $response, string $path): array
    {
        $data = $response->json($path);
        self::assertIsArray($data, \sprintf('No linkage array at %s.', $path));

        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertIsString($identifier['id'] ?? null);
            $ids[] = $identifier['id'];
        }

        return $ids;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    private function included(TestResponse $response, string $type): array
    {
        $included = $response->json('included') ?? [];
        self::assertIsArray($included);

        $found = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            if (($resource['type'] ?? null) === $type && \is_string($resource['id'] ?? null)) {
                $found[] = $type . ':' . $resource['id'];
            }
        }
        \sort($found);

        return $found;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function includedPath(TestResponse $response, string $type, string $id): string
    {
        $included = $response->json('included') ?? [];
        self::assertIsArray($included);

        foreach ($included as $index => $resource) {
            if (\is_array($resource) && ($resource['type'] ?? null) === $type && ($resource['id'] ?? null) === $id) {
                return 'included.' . $index;
            }
        }

        self::fail(\sprintf('%s %s is not included.', $type, $id));
    }
}
