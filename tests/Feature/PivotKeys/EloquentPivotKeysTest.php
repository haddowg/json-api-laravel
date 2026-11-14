<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\PivotKeys;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PivotKeysServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkCampaign;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkDefinition;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkTemplate;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkTemplatePlaceholder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * `campaign-templates` is keyed by a non-incrementing `campaign_id` and
 * `placeholder-definitions` by a binary UUID. They meet through a custom pivot with a binary
 * UUID id its constructor mints. Every pivot read runs in both directions, so each side is the
 * related type of one of them.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentPivotKeysTest extends Orchestra
{
    private const string FIRST_NAME = '01932f5e-1a2b-7c3d-8e4f-000000000001';

    private const string LAST_NAME = '01932f5e-1a2b-7c3d-8e4f-000000000002';

    private const string COMPANY = '01932f5e-1a2b-7c3d-8e4f-000000000003';

    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string COUNTABLE = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/countable/"';

    private const string QUERIES = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/relationship-queries/ https://haddowg.github.io/json-api/profiles/countable/"';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, PivotKeysServiceProvider::class];
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
        Schema::create('pk_campaigns', static function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
        });
        Schema::create('pk_templates', static function (Blueprint $table): void {
            $table->integer('campaign_id')->primary();
            $table->string('name');
        });
        Schema::create('pk_definitions', static function (Blueprint $table): void {
            $table->binary('id', 16, fixed: true)->primary();
            $table->string('name');
        });
        Schema::create('pk_template_placeholders', static function (Blueprint $table): void {
            $table->binary('id', 16, fixed: true)->primary();
            $table->integer('template_id');
            $table->binary('definition_id', 16, fixed: true);
            $table->string('default_override')->nullable();
        });

        foreach ([10 => 'alpha', 20 => 'beta', 30 => 'gamma'] as $id => $name) {
            PkCampaign::query()->create(['id' => $id, 'name' => 'Campaign ' . $id]);
            PkTemplate::query()->create(['campaign_id' => $id, 'name' => $name]);
        }
        foreach ([self::FIRST_NAME => 'first-name', self::LAST_NAME => 'last-name', self::COMPANY => 'company'] as $id => $name) {
            PkDefinition::query()->create(['id' => self::bytes($id), 'name' => $name]);
        }

        $this->link(10, self::FIRST_NAME, 'Ann');
        $this->link(10, self::LAST_NAME, null);
        $this->link(20, self::FIRST_NAME, 'Bob');
        $this->link(20, self::COMPANY, 'Acme');
    }

    #[Test]
    public function theRelatedEndpointReadsTemplatesThroughThePivot(): void
    {
        $rows = $this->rows($this->read('/api/placeholder-definitions/' . self::FIRST_NAME . '/templates?sort=name'), 'data');

        self::assertSame(['10', '20'], \array_column($rows, 'id'));
        self::assertSame(['Ann', 'Bob'], \array_map(fn(array $row): mixed => $this->override($row), $rows));
    }

    #[Test]
    public function theRelatedEndpointSortsFiltersAndPagesByThePivot(): void
    {
        $base = '/api/placeholder-definitions/' . self::FIRST_NAME . '/templates';

        self::assertSame(['20', '10'], \array_column($this->rows($this->read($base . '?sort=-defaultOverride'), 'data'), 'id'));
        self::assertSame(['20'], \array_column($this->rows($this->read($base . '?filter[defaultOverride]=Bob'), 'data'), 'id'));

        $paged = $this->read($base . '?sort=name&page[size]=1&page[number]=2&withCount=_self_', self::COUNTABLE);
        self::assertSame(['20'], \array_column($this->rows($paged, 'data'), 'id'));
        self::assertSame(2, $paged->json('meta.page.total'));
    }

    #[Test]
    public function theRelationshipEndpointRendersTemplateLinkage(): void
    {
        $rows = $this->rows($this->read('/api/placeholder-definitions/' . self::FIRST_NAME . '/relationships/templates?sort=-defaultOverride'), 'data');

        self::assertSame([['type' => 'campaign-templates', 'id' => '20'], ['type' => 'campaign-templates', 'id' => '10']], \array_map(
            static fn(array $row): array => ['type' => $row['type'] ?? null, 'id' => $row['id'] ?? null],
            $rows,
        ));
    }

    #[Test]
    public function aDefinitionRendersItsTemplateLinkage(): void
    {
        self::assertEqualsCanonicalizing(['10', '20'], $this->linkageIds($this->resource($this->read('/api/placeholder-definitions/' . self::FIRST_NAME)), 'templates'));

        $rows = $this->rows($this->read('/api/placeholder-definitions?sort=name'), 'data');
        self::assertSame(['company', 'first-name', 'last-name'], \array_column(\array_column($rows, 'attributes'), 'name'));
        self::assertSame(['20'], $this->linkageIds($rows[0], 'templates'));
        self::assertEqualsCanonicalizing(['10', '20'], $this->linkageIds($rows[1], 'templates'));
        self::assertSame(['10'], $this->linkageIds($rows[2], 'templates'));
    }

    #[Test]
    public function anIncludeBatchesAndWindowsTheTemplates(): void
    {
        $included = \array_map(static fn(array $row): string => \sprintf('%s:%s', \is_string($row['type'] ?? null) ? $row['type'] : '', \is_string($row['id'] ?? null) ? $row['id'] : ''), $this->rows($this->read('/api/placeholder-definitions?sort=name&include=templates'), 'included'));
        \sort($included);
        self::assertSame(['campaign-templates:10', 'campaign-templates:20'], $included);

        $windowed = $this->rows($this->read('/api/placeholder-definitions?sort=name&include=templates&relatedQuery[templates][sort]=-name', self::QUERIES), 'data');
        self::assertSame(['20', '10'], $this->linkageIds($windowed[1], 'templates'));

        $byPivot = $this->rows($this->read('/api/placeholder-definitions?sort=name&include=templates&relatedQuery[templates][sort]=defaultOverride', self::QUERIES), 'data');
        self::assertSame(['10', '20'], $this->linkageIds($byPivot[1], 'templates'));
    }

    #[Test]
    public function withCountCountsTemplatesPerDefinition(): void
    {
        $rows = $this->rows($this->read('/api/placeholder-definitions?sort=name&withCount=templates', self::COUNTABLE), 'data');

        self::assertSame([1, 2, 1], \array_map(fn(array $row): mixed => $this->total($row, 'templates'), $rows));
    }

    #[Test]
    public function theForwardDirectionReadsDefinitionsThroughThePivot(): void
    {
        self::assertSame([self::COMPANY, self::FIRST_NAME], \array_column($this->rows($this->read('/api/campaign-templates/20/placeholders?sort=name'), 'data'), 'id'));

        $templates = $this->rows($this->read('/api/campaign-templates?sort=name&include=placeholders&withCount=placeholders', self::COUNTABLE), 'data');
        self::assertSame([2, 2, 0], \array_map(fn(array $row): mixed => $this->total($row, 'placeholders'), $templates));
    }

    #[Test]
    public function aRelatedQueryAppliesThePivotFiltersToTheWindowAndItsCount(): void
    {
        $templates = $this->rows($this->read('/api/campaign-templates?sort=name&include=placeholders&withCount=placeholders&relatedQuery[placeholders][filter][defaultOverride]=Bob', self::QUERIES), 'data');
        self::assertSame([[], [self::FIRST_NAME], []], \array_map(fn(array $row): array => $this->linkageIds($row, 'placeholders'), $templates));
        self::assertSame([0, 1, 0], \array_map(fn(array $row): mixed => $this->total($row, 'placeholders'), $templates));

        $definitions = $this->rows($this->read('/api/placeholder-definitions?sort=name&include=templates&withCount=templates&relatedQuery[templates][filter][defaultOverride]=Bob', self::QUERIES), 'data');
        self::assertSame([[], ['20'], []], \array_map(fn(array $row): array => $this->linkageIds($row, 'templates'), $definitions));
        self::assertSame([0, 1, 0], \array_map(fn(array $row): mixed => $this->total($row, 'templates'), $definitions));
    }

    #[Test]
    public function addingAMemberBuildsThePivotRowThroughItsConstructor(): void
    {
        $this->write('POST', '/api/campaign-templates/30/relationships/placeholders', ['data' => [
            ['type' => 'placeholder-definitions', 'id' => self::COMPANY, 'meta' => ['pivot' => ['defaultOverride' => 'Initech']]],
        ]])->assertSuccessful();
        $this->write('POST', '/api/placeholder-definitions/' . self::LAST_NAME . '/relationships/templates', ['data' => [
            ['type' => 'campaign-templates', 'id' => '30', 'meta' => ['pivot' => ['defaultOverride' => 'Smith']]],
        ]])->assertSuccessful();

        $rows = $this->rows($this->read('/api/campaign-templates/30/placeholders?sort=name'), 'data');
        self::assertSame([self::COMPANY, self::LAST_NAME], \array_column($rows, 'id'));
        self::assertSame(['Initech', 'Smith'], \array_map(fn(array $row): mixed => $this->override($row), $rows));

        $ids = DB::table('pk_template_placeholders')->where('template_id', 30)->pluck('id')->all();
        self::assertCount(2, $ids);
        foreach ($ids as $id) {
            self::assertIsString($id);
            self::assertSame(16, \strlen($id), 'the constructor mints a binary UUID id');
        }
    }

    #[Test]
    public function theInverseSideReplacesAndRemovesMembers(): void
    {
        $base = '/api/placeholder-definitions/' . self::FIRST_NAME . '/relationships/templates';

        $this->write('PATCH', $base, ['data' => [
            ['type' => 'campaign-templates', 'id' => '20', 'meta' => ['pivot' => ['defaultOverride' => 'Bobby']]],
            ['type' => 'campaign-templates', 'id' => '30', 'meta' => ['pivot' => ['defaultOverride' => 'Cat']]],
        ]])->assertSuccessful();

        $rows = $this->rows($this->read('/api/placeholder-definitions/' . self::FIRST_NAME . '/templates?sort=name'), 'data');
        self::assertSame(['20', '30'], \array_column($rows, 'id'));
        self::assertSame(['Bobby', 'Cat'], \array_map(fn(array $row): mixed => $this->override($row), $rows));

        $this->write('DELETE', $base, ['data' => [['type' => 'campaign-templates', 'id' => '20']]])->assertSuccessful();
        self::assertSame(['30'], \array_column($this->rows($this->read('/api/placeholder-definitions/' . self::FIRST_NAME . '/templates'), 'data'), 'id'));
        self::assertSame(1, DB::table('pk_template_placeholders')->where('template_id', 20)->count());
    }

    #[Test]
    public function aCreateBuildsItsPivotRowsThroughTheConstructor(): void
    {
        $response = $this->write('POST', '/api/placeholder-definitions', ['data' => [
            'type' => 'placeholder-definitions',
            'attributes' => ['name' => 'job-title'],
            'relationships' => ['templates' => ['data' => [
                ['type' => 'campaign-templates', 'id' => '30', 'meta' => ['pivot' => ['defaultOverride' => 'CTO']]],
            ]]],
        ]]);
        $response->assertCreated();
        $id = $response->json('data.id');
        self::assertIsString($id);

        $rows = $this->rows($this->read('/api/placeholder-definitions/' . $id . '/templates'), 'data');
        self::assertSame(['30'], \array_column($rows, 'id'));
        self::assertSame('CTO', $this->override($rows[0]));
        self::assertSame(1, DB::table('pk_template_placeholders')->where('template_id', 30)->count());
    }

    #[Test]
    public function aTemplateIsFilteredThroughItsPivotRowsToADefinition(): void
    {
        self::assertSame(['10', '20'], $this->templateIds('filter[definition]=' . self::FIRST_NAME));
        self::assertSame(['10', '20'], $this->templateIds('filter[definition]=' . self::LAST_NAME . ',' . self::COMPANY));
        self::assertSame(['20'], $this->templateIds('filter[definitionIs]=' . self::COMPANY));
        self::assertSame([], $this->templateIds('filter[definitionIs]=01932f5e-1a2b-7c3d-8e4f-0000000000ff'));
    }

    #[Test]
    public function aTemplateIsFilteredThroughItsPivotRelation(): void
    {
        self::assertSame(['10'], $this->templateIds('filter[placeholder]=' . self::LAST_NAME));
        self::assertSame(['10', '20'], $this->templateIds('filter[placeholder]=' . self::LAST_NAME . ',' . self::COMPANY));
        self::assertSame(['20'], $this->templateIds('filter[placeholderName]=company'));
        self::assertSame(['10', '20'], $this->templateIds('filter[hasPlaceholders]=1'));
    }

    #[Test]
    public function aDefinitionIsFilteredThroughItsTemplates(): void
    {
        self::assertSame(['company', 'first-name'], $this->definitionNames('filter[template]=20'));
        self::assertSame(['first-name', 'last-name'], $this->definitionNames('filter[usedBy]=10'));
        self::assertSame(['company', 'first-name', 'last-name'], $this->definitionNames('filter[usedBy]=10,20'));
    }

    #[Test]
    public function aDefinitionIsFilteredByItsBinaryId(): void
    {
        self::assertSame(['company', 'last-name'], $this->definitionNames('filter[id]=' . self::LAST_NAME . ',' . self::COMPANY));
        self::assertSame(['first-name'], $this->definitionNames('filter[idIs]=' . self::FIRST_NAME));
        self::assertSame([], $this->definitionNames('filter[idIs]=not-a-uuid'));
    }

    private function link(int $template, string $definition, ?string $override): void
    {
        $row = new PkTemplatePlaceholder(['template_id' => $template, 'definition_id' => self::bytes($definition), 'default_override' => $override]);
        $row->save();
    }

    private static function bytes(string $uuid): string
    {
        $bytes = \hex2bin(\str_replace('-', '', $uuid));
        \assert(\is_string($bytes));

        return $bytes;
    }

    /**
     * @return list<string>
     */
    private function templateIds(string $query): array
    {
        /** @var list<string> */
        return \array_column($this->rows($this->read('/api/campaign-templates?sort=name&' . $query), 'data'), 'id');
    }

    /**
     * @return list<string>
     */
    private function definitionNames(string $query): array
    {
        return \array_column(\array_column($this->rows($this->read('/api/placeholder-definitions?sort=name&' . $query), 'data'), 'attributes'), 'name');
    }

    /**
     * @param array<string, mixed> $resource
     *
     * @return list<string>
     */
    private function linkageIds(array $resource, string $relation): array
    {
        $relationships = $resource['relationships'] ?? [];
        self::assertIsArray($relationships);
        $object = $relationships[$relation] ?? [];
        self::assertIsArray($object);
        self::assertArrayHasKey('data', $object, \sprintf('the %s relationship renders data', $relation));
        $data = $object['data'];
        self::assertIsArray($data);

        return \array_values(\array_map(static fn(mixed $row): string => \is_array($row) && \is_string($row['id'] ?? null) ? $row['id'] : '', $data));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, mixed>
     */
    private function resource(TestResponse $response): array
    {
        $data = $response->json('data');
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<array<string, mixed>>
     */
    private function rows(TestResponse $response, string $member): array
    {
        $rows = $response->json($member);
        self::assertIsArray($rows);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function override(array $row): mixed
    {
        $meta = $row['meta'] ?? [];
        self::assertIsArray($meta);
        $pivot = $meta['pivot'] ?? [];
        self::assertIsArray($pivot);

        return $pivot['defaultOverride'] ?? null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function total(array $row, string $relation): mixed
    {
        $relationships = $row['relationships'] ?? [];
        self::assertIsArray($relationships);
        $object = $relationships[$relation] ?? [];
        self::assertIsArray($object);
        $meta = $object['meta'] ?? [];
        self::assertIsArray($meta);

        return $meta['total'] ?? null;
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri, string $accept = self::MEDIA_TYPE): TestResponse
    {
        $response = $this->get($uri, ['Accept' => $accept]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function write(string $method, string $uri, array $body): TestResponse
    {
        $response = $this->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => self::MEDIA_TYPE,
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
        ], (string) \json_encode($body));
        self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }
}
