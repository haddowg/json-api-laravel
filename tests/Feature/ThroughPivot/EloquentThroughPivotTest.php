<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ThroughPivot;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Filter\RelationHop;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentPivotRowRelations;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentSortHandler;
use haddowg\JsonApiLaravel\DataProvider\FilterPaths;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot\ThroughPivotServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot\TpPlaylistResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot\TpTrack;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot\TpTrackResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot\TpUser;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot\TpUserResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * `pivot` segments in `->through()` paths on the Eloquent provider: a leading `pivot` on a
 * relation's related and relationship endpoints and in `relatedQuery`, a pivot field leaf, a
 * relation the pivot model holds, a `pivot` mid-path, the related types' scopes and read
 * gates, the sorts on offset and cursor pages, and the deprecated `pivot.`-prefixed traversals
 * beside their migrated forms. See {@see ThroughPivotServiceProvider} for the rows.
 *
 * @internal
 */
#[CoversClass(EloquentPivotRowRelations::class)]
#[CoversClass(EloquentSortHandler::class)]
final class EloquentThroughPivotTest extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string QUERIES = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/relationship-queries/"';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, ThroughPivotServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineDatabaseMigrations(): void
    {
        ThroughPivotServiceProvider::migrate();
    }

    #[Test]
    public function aLeadingPivotFiltersTheRelatedEndpointByARelationThePivotRowHolds(): void
    {
        self::assertSame(['1'], $this->ids('/api/tpTracks/1/playlists?filter[addedBy]=sam'));
        self::assertSame(['2'], $this->ids('/api/tpTracks/1/playlists?filter[addedBy]=kim'));
        self::assertSame(['2'], $this->ids('/api/tpTracks/1/playlists?filter[adder]=2'));
        self::assertSame(['1', '2'], $this->ids('/api/tpTracks/1/playlists?filter[adder][]=1&filter[adder][]=2'));
    }

    #[Test]
    public function aFamilyResolvingADeprecatedPathNoTypeCanGateAnswers500(): void
    {
        $log = Log::spy();

        $this->send('admin', '/api/tpTracks/1/playlists?filter[owner.name]=sam')->assertStatus(500);

        $log->shouldHaveReceived('error')->once()->withArgs(static fn(string $message): bool => \str_contains($message, 'The filter family "owner" of relation "playlists" on type "tpTracks" resolved the filter "owner.name" to the path "pivot.owner.name", which goes on from the pivot row to a model no single registered type serves')
            && \str_contains($message, 'This is a declaration fault'));
    }

    #[Test]
    public function aLeadingPivotFiltersTheRelationshipEndpoint(): void
    {
        self::assertSame(['2'], $this->ids('/api/tpTracks/1/relationships/playlists?filter[addedBy]=kim'));
        self::assertSame(['1'], $this->ids('/api/tpTracks/1/relationships/playlists?filter[position]=1'));
    }

    #[Test]
    public function aPivotFieldLeafReadsTheStoredPivotMember(): void
    {
        self::assertSame(['2'], $this->ids('/api/tpTracks/1/playlists?filter[addedAt][min]=2026-02-01T00:00:00Z'));
        self::assertSame(['1'], $this->ids('/api/tpTracks/1/playlists?filter[addedAt][max]=2026-02-01T00:00:00Z'));
        self::assertSame(['1'], $this->ids('/api/tpTracks/1/playlists?filter[position]=1'));
    }

    #[Test]
    public function aLeadingPivotWorksInARelatedQuery(): void
    {
        $document = $this->document('/api/tpTracks/1?include=playlists&relatedQuery[playlists][filter][addedBy]=kim', self::QUERIES);
        self::assertSame(['2'], $this->linkage($document, 'playlists'));

        $sorted = $this->document('/api/tpTracks/1?include=playlists&relatedQuery[playlists][sort]=-added', self::QUERIES);
        self::assertSame(['2', '1'], $this->linkage($sorted, 'playlists'));

        $byAdder = $this->document('/api/tpTracks/1?include=playlists&relatedQuery[playlists][sort]=adder', self::QUERIES);
        self::assertSame(['2', '1'], $this->linkage($byAdder, 'playlists'));
    }

    #[Test]
    public function aSortReadsAPivotFieldOrARelationThePivotRowHolds(): void
    {
        self::assertSame(['2', '1'], $this->ids('/api/tpTracks/1/playlists?sort=-added'));
        self::assertSame(['1', '2'], $this->ids('/api/tpTracks/1/playlists?sort=added'));
        self::assertSame(['2', '1'], $this->ids('/api/tpTracks/1/playlists?sort=adder'));
        self::assertSame(['1', '2'], $this->ids('/api/tpTracks/1/relationships/playlists?sort=-adder'));
    }

    #[Test]
    public function aCursorPageSortsByALeadingPivotAndFollowsItsNextLink(): void
    {
        foreach (['/api/tpTracks/1/cursorPlaylists', '/api/tpTracks/1/relationships/cursorPlaylists'] as $base) {
            foreach (['' => ['2', '1'], '?sort=-added' => ['2', '1'], '?sort=added' => ['1', '2'], '?sort=adder' => ['2', '1'], '?sort=-adder' => ['1', '2']] as $query => $expected) {
                self::assertSame($expected, $this->cursorPages($base . $query), $base . $query);
            }
        }
    }

    #[Test]
    public function anIncludedCursorRelationReadsItsFirstPageInOneWindowPerRelation(): void
    {
        $default = $this->document('/api/tpTracks?include=cursorPlaylists');
        self::assertSame(['1' => ['2'], '2' => ['1'], '3' => ['3']], $this->linkageByParent($default, 'cursorPlaylists'));

        // The first page of every parent is one windowed query, the only one reading the
        // users the sort orders by.
        DB::enableQueryLog();
        $this->document('/api/tpTracks?include=cursorPlaylists&relatedQuery[cursorPlaylists][sort]=adder', self::QUERIES);
        $windowed = \array_values(\array_filter(\array_map(static fn(array $entry): string => (string) $entry['query'], DB::getQueryLog()), static fn(string $sql): bool => \str_contains($sql, 'tp_users')));
        DB::disableQueryLog();
        self::assertCount(1, $windowed, \implode("\n", $windowed));
        self::assertStringContainsStringIgnoringCase('row_number', $windowed[0]);

        foreach (['-added' => ['1' => ['2'], '2' => ['1'], '3' => ['3']], 'added' => ['1' => ['1'], '2' => ['1'], '3' => ['3']], 'adder' => ['1' => ['2'], '2' => ['1'], '3' => ['3']], '-adder' => ['1' => ['1'], '2' => ['1'], '3' => ['3']]] as $sort => $expected) {
            $document = $this->document('/api/tpTracks?include=cursorPlaylists&relatedQuery[cursorPlaylists][sort]=' . $sort, self::QUERIES);
            self::assertSame($expected, $this->linkageByParent($document, 'cursorPlaylists'), $sort);
        }
    }

    #[Test]
    public function aPivotMidPathFiltersThroughTheRowsOfEachHop(): void
    {
        self::assertSame(['1', '2'], $this->ids('/api/tpAlbums?filter[adder]=kim'));
        self::assertSame(['1'], $this->ids('/api/tpAlbums?filter[adder]=sam'));
        self::assertSame(['2'], $this->ids('/api/tpAlbums?filter[addedAt][min]=2026-04-01T00:00:00Z'));
        self::assertSame(['1'], $this->ids('/api/tpAlbums?filter[addedAt][max]=2026-02-15T00:00:00Z'));
    }

    #[Test]
    public function aPivotOnTheFirstRelationOfACollectionGoesOnPastThePivotRow(): void
    {
        self::assertSame(['1', '3'], $this->ids('/api/tpTracks?filter[playlistAdder]=kim'));
        self::assertSame(['1'], $this->ids('/api/tpTracks?filter[playlistAdder]=sam'));
        self::assertSame([], $this->ids('/api/tpTracks?filter[playlistAdder]=ghost'));
    }

    #[Test]
    public function aLeadingPivotSortUnderARelationTheCallerMayNotReadOrdersAsNull(): void
    {
        $relation = null;
        foreach ((new TpTrackResource())->allFields() as $field) {
            if ($field instanceof BelongsToMany && $field->name() === 'playlists') {
                $relation = $field;
            }
        }
        self::assertInstanceOf(BelongsToMany::class, $relation);
        $users = (new TpUserResource())->allFields();
        $resolver = new ThroughPathResolver(static fn(string $type): ?array => $type === 'tpUsers' ? $users : null, new EloquentPivotRowRelations(['tpTracks' => TpTrack::class, 'tpUsers' => TpUser::class]));
        // The paths of a default order whose relation the caller may not read.
        $paths = new FilterPaths($resolver, (new TpPlaylistResource())->allFields(), 'tpPlaylists', refused: [$relation], under: new RelationHop($relation, 'tpTracks', 'playlists'));

        foreach (['added' => SortByField::make('added', 'addedAt')->through('pivot'), 'adder' => SortByField::make('adder', 'name')->through('pivot.addedBy')] as $key => $sort) {
            /** @var EloquentBelongsToMany<Model, Model, Pivot, 'pivot'> $eloquent */
            $eloquent = TpTrack::query()->findOrFail(1)->playlists();
            /** @var Builder<Model> $builder */
            $builder = $eloquent->getQuery()->select($eloquent->getRelated()->qualifyColumn('*'));
            $handler = (new EloquentSortHandler())->forPivot($eloquent)->forPaths($paths);
            $handler->apply([new SortDirective($paths->resolveSort($sort), descending: true)], $builder);

            self::assertStringContainsString('1 = 0', $builder->toSql(), $key);
            $ids = \array_map(static fn(Model $playlist): string => \is_scalar($key = $playlist->getKey()) ? (string) $key : '', \array_values($builder->get()->all()));
            \sort($ids);
            self::assertSame(['1', '2'], $ids, $key);
        }
    }

    #[Test]
    public function aRowTheReachedTypesScopeHidesNeverMatches(): void
    {
        self::assertSame([], $this->ids('/api/tpTracks/2/playlists?filter[addedBy]=ghost'));
        self::assertSame([], $this->ids('/api/tpAlbums?filter[adder]=ghost'));
    }

    #[Test]
    public function aHopTheCallerMayNotReadIsRefused(): void
    {
        foreach (['/api/tpTracks/1/playlists?filter[auditedBy]=audrey', '/api/tpTracks/1/playlists?filter[unaudited]=1', '/api/tpAlbums?filter[auditor]=audrey'] as $uri) {
            $this->send('ada', $uri)->assertStatus(403);
        }

        self::assertSame(['1'], $this->ids('/api/tpTracks/1/playlists?filter[auditedBy]=audrey'));
        self::assertSame(['2'], $this->ids('/api/tpTracks/1/playlists?filter[unaudited]=1'));
        self::assertSame(['1'], $this->ids('/api/tpAlbums?filter[auditor]=audrey'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    private static function migratedPivotTraversals(): iterable
    {
        yield 'a value through a pivot row relation' => ['legacyAddedBy=kim', 'addedBy=kim'];
        yield 'an id through a pivot row relation' => ['legacyAdder=1', 'adder=1'];
        yield 'a list of ids' => ['legacyAdder=1,2', 'adder=1,2'];
    }

    #[Test]
    public function aMigratedPivotTraversalMatchesWhatItReplaces(): void
    {
        foreach (self::migratedPivotTraversals() as $case => [$legacy, $migrated]) {
            foreach (['/api/tpTracks/1/playlists', '/api/tpTracks/1/relationships/playlists', '/api/tpTracks/3/playlists'] as $uri) {
                self::assertSame(
                    $this->ids($uri . '?filter[' . \str_replace('=', ']=', $legacy)),
                    $this->ids($uri . '?filter[' . \str_replace('=', ']=', $migrated)),
                    $case . ' on ' . $uri,
                );
            }
        }
    }

    #[Test]
    public function aMigratedExistenceFilterMatchesWhatItReplaces(): void
    {
        self::assertSame($this->ids('/api/tpTracks/1/playlists?filter[legacyUnaudited]=1'), $this->ids('/api/tpTracks/1/playlists?filter[unaudited]=1'));
    }

    #[Test]
    public function bothFormsApplyTheReachedModelsGlobalScope(): void
    {
        // The prefixed traversal built its EXISTS from the pivot model's relation query, which
        // applies the related model's global scopes, so a hidden user matched neither before
        // nor after the migration.
        self::assertSame([], $this->ids('/api/tpTracks/2/playlists?filter[legacyAddedBy]=ghost'));
        self::assertSame([], $this->ids('/api/tpTracks/2/playlists?filter[addedBy]=ghost'));
    }

    #[Test]
    public function bothFormsHideTheSameRowsFromEveryCaller(): void
    {
        // Ghost, user 3, added track 2 to playlist 1, and the users' scope hides him.
        foreach (['admin', 'ada'] as $user) {
            foreach ([['legacyAdder=3', 'adder=3'], ['legacyAdder=1,3', 'adder=1,3'], ['legacyAddedBy=ghost', 'addedBy=ghost'], ['legacyAuditedBy=audrey', 'auditedBy=audrey']] as [$legacy, $migrated]) {
                if ($user === 'ada' && \str_contains($legacy, 'Audited')) {
                    continue;
                }
                foreach (['/api/tpTracks/1/playlists', '/api/tpTracks/2/playlists'] as $uri) {
                    self::assertSame(
                        $this->ids($uri . '?filter[' . \str_replace('=', ']=', $migrated), $user),
                        $this->ids($uri . '?filter[' . \str_replace('=', ']=', $legacy), $user),
                        $legacy . ' on ' . $uri . ' for ' . $user,
                    );
                }
            }
        }
    }

    #[Test]
    public function bothFormsAreRefusedThroughAHopTheCallerMayNotRead(): void
    {
        // Only admins may list auditors, so a pivot row's auditor is a hop ada may not read.
        foreach (['legacyAuditedBy=audrey' => 'auditedBy=audrey', 'legacyUnaudited=1' => 'unaudited=1'] as $legacy => $migrated) {
            foreach ([$legacy, $migrated] as $filter) {
                $response = $this->send('ada', '/api/tpTracks/1/playlists?filter[' . \str_replace('=', ']=', $filter));
                $response->assertStatus(403);
                $response->assertJsonPath('errors.0.code', 'ACCESS_DENIED');
            }
        }
        self::assertSame(['1'], $this->ids('/api/tpTracks/1/playlists?filter[legacyAuditedBy]=audrey'));
    }

    #[Test]
    public function aLeadingPivotFilterIsUnrecognisedWhereNoPivotQueryRuns(): void
    {
        // An included to-many's linkage reads no pivot row, so its relatedQuery filter by one is
        // the 400 the related endpoint gives an unknown key, as a `pivot.` column filter is.
        $this->send('admin', '/api/tpAlbums/1?include=tracks&relatedQuery[tracks][filter][addedBy]=kim', self::QUERIES)->assertStatus(400);
    }

    #[Test]
    public function theDocumentTypesALeadingPivotFilterByItsField(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'jsonapi-pivot-openapi-');
        self::assertIsString($file);
        self::assertSame(0, Artisan::call('jsonapi:openapi:export', ['--output' => $file]));
        $document = \json_decode((string) \file_get_contents($file), true);
        \unlink($file);
        self::assertIsArray($document);
        $parameters = self::at($document, 'paths', '/tpTracks/{id}/playlists', 'get', 'parameters');
        self::assertIsArray($parameters);
        $found = [];
        foreach ($parameters as $parameter) {
            if (\is_array($parameter) && \is_string($parameter['name'] ?? null)) {
                $found[$parameter['name']] = $parameter;
            }
        }
        self::assertSame(['type' => 'integer'], $found['filter[position]']['schema'] ?? null);
        self::assertSame('Filter the collection by `position`, on the pivot row.', $found['filter[position]']['description'] ?? null);
        self::assertSame(['type' => 'string'], $found['filter[addedBy]']['schema'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function ids(string $uri, string $user = 'admin'): array
    {
        $data = $this->document($uri, self::MEDIA_TYPE, $user)['data'] ?? null;
        self::assertIsArray($data);

        return self::idsOf($data);
    }

    /**
     * @param array<mixed> $document
     *
     * @return list<string>
     */
    private function linkage(array $document, string $relation): array
    {
        $data = self::at($document, 'data', 'relationships', $relation, 'data');
        self::assertIsArray($data);

        return self::idsOf($data);
    }

    /**
     * Each primary resource's linkage ids for `$relation`, keyed by its id.
     *
     * @param array<mixed> $document
     *
     * @return array<string, list<string>>
     */
    private function linkageByParent(array $document, string $relation): array
    {
        $data = self::at($document, 'data');
        self::assertIsArray($data);
        $byParent = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $byParent[$id] = $this->linkage(['data' => $resource], $relation);
        }
        \ksort($byParent);

        return $byParent;
    }

    /**
     * The ids of every cursor page of `$uri`, following each `next` link.
     *
     * @return list<string>
     */
    private function cursorPages(string $uri): array
    {
        $ids = [];
        for ($page = 0; $uri !== null && $page < 10; ++$page) {
            $document = $this->document($uri);
            $data = $document['data'] ?? null;
            self::assertIsArray($data);
            $ids = [...$ids, ...self::idsOf($data)];
            $next = self::at($document, 'links', 'next');
            $href = \is_array($next) ? $next['href'] ?? null : $next;
            $uri = \is_string($href) ? (string) \preg_replace('#^https?://[^/]+#', '', $href) : null;
        }

        return $ids;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function idsOf(array $data): array
    {
        return \array_values(\array_map(static fn(mixed $row): string => \is_array($row) && \is_string($row['id'] ?? null) ? $row['id'] : '', $data));
    }

    /**
     * @return array<mixed>
     */
    private function document(string $uri, string $accept = self::MEDIA_TYPE, string $user = 'admin'): array
    {
        $document = $this->send($user, $uri, $accept)->assertOk()->json();
        self::assertIsArray($document);

        return $document;
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(string $user, string $uri, string $accept = self::MEDIA_TYPE): TestResponse
    {
        $this->actingAs(new User(['id' => $user === 'admin' ? 3 : 1, 'name' => $user, 'is_admin' => $user === 'admin']));

        return $this->call('GET', $uri, [], [], [], ['HTTP_ACCEPT' => $accept]);
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($value, $key);
            $value = $value[$key] ?? null;
        }

        return $value;
    }
}
