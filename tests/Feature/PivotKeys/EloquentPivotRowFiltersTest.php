<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\PivotKeys;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PivotKeysServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkDefinition;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkPlaceholderTarget;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkTemplate;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\PkTemplatePlaceholder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `placeholders` relation's `pivot.`-prefixed relationship filters test the row that
 * links the template to each definition, not the definition. First-name is shared: its row
 * on template 10 targets the header, its row on template 20 targets nothing, and template
 * 20's company row targets the footer and the header.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentPivotRowFiltersTest extends Orchestra
{
    private const string FIRST_NAME = '01932f5e-1a2b-7c3d-8e4f-000000000001';

    private const string LAST_NAME = '01932f5e-1a2b-7c3d-8e4f-000000000002';

    private const string COMPANY = '01932f5e-1a2b-7c3d-8e4f-000000000003';

    private const string HEADER = '01932f5e-1a2b-7c3d-8e4f-0000000000a1';

    private const string FOOTER = '01932f5e-1a2b-7c3d-8e4f-0000000000a2';

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
        Schema::create('pk_placeholder_targets', static function (Blueprint $table): void {
            $table->binary('id', 16, fixed: true)->primary();
            $table->string('name');
        });
        Schema::create('pk_placeholder_target_links', static function (Blueprint $table): void {
            $table->binary('placeholder_id', 16, fixed: true);
            $table->binary('target_id', 16, fixed: true);
        });

        foreach ([10 => 'alpha', 20 => 'beta', 30 => 'gamma'] as $id => $name) {
            PkTemplate::query()->create(['campaign_id' => $id, 'name' => $name]);
        }
        foreach ([self::FIRST_NAME => 'first-name', self::LAST_NAME => 'last-name', self::COMPANY => 'company'] as $id => $name) {
            PkDefinition::query()->create(['id' => self::bytes($id), 'name' => $name]);
        }
        foreach ([self::HEADER => 'header', self::FOOTER => 'footer'] as $id => $name) {
            PkPlaceholderTarget::query()->create(['id' => $id, 'name' => $name]);
        }

        $this->link(10, self::FIRST_NAME, 'Ann', [self::HEADER]);
        $this->link(10, self::LAST_NAME, null, []);
        $this->link(20, self::FIRST_NAME, 'Bob', []);
        $this->link(20, self::COMPANY, 'Acme', [self::FOOTER, self::HEADER]);
    }

    #[Test]
    public function aPivotRowFilterReadsTheRowOfThisTemplate(): void
    {
        self::assertSame([self::LAST_NAME], $this->related(10, 'filter[unused]=1'));
        self::assertSame([self::FIRST_NAME], $this->related(20, 'filter[unused]=1'));
        self::assertSame([self::FIRST_NAME], $this->related(10, 'filter[used]=1'));
        self::assertSame([self::COMPANY], $this->related(20, 'filter[used]=1'));
        self::assertSame([self::COMPANY], $this->related(20, 'filter[targetName]=footer'));
        self::assertSame([self::FIRST_NAME], $this->related(10, 'filter[target]=' . self::HEADER));
        self::assertSame([self::COMPANY], $this->related(20, 'filter[target]=' . self::HEADER . ',' . self::FOOTER));
        self::assertSame([], $this->related(20, 'filter[unused]=1&filter[defaultOverride]=Acme'));
    }

    #[Test]
    public function aPivotRowFilterAppliesOnTheRelationshipEndpoint(): void
    {
        $linkage = fn(int $template, string $query): array => \array_column($this->rows($this->read('/api/campaign-templates/' . $template . '/relationships/placeholders?' . $query), 'data'), 'id');

        self::assertSame([self::LAST_NAME], $linkage(10, 'filter[unused]=1'));
        self::assertSame([self::FIRST_NAME], $linkage(20, 'filter[unused]=1'));
        self::assertSame([self::COMPANY], $linkage(20, 'filter[targetName]=footer'));
    }

    #[Test]
    public function aPivotRowFilterCountsAndPagesThePivotRead(): void
    {
        $response = $this->read('/api/campaign-templates/20/placeholders?filter[used]=1&page[size]=1&withCount=_self_', self::COUNTABLE);

        self::assertSame([self::COMPANY], \array_column($this->rows($response, 'data'), 'id'));
        self::assertSame(1, $response->json('meta.page.total'));
    }

    #[Test]
    public function aPivotRowFilterNarrowsARelatedQueryWindowAndItsCount(): void
    {
        $rows = $this->rows($this->read('/api/campaign-templates?sort=name&include=placeholders&withCount=placeholders&relatedQuery[placeholders][filter][unused]=1', self::QUERIES), 'data');
        self::assertSame([[self::LAST_NAME], [self::FIRST_NAME], []], $this->linkages($rows));
        self::assertSame([1, 1, 0], $this->totals($rows));

        $paged = $this->rows($this->read('/api/campaign-templates?sort=name&include=placeholders&relatedQuery[placeholders][filter][targetName]=header&relatedQuery[placeholders][sort]=-name', self::QUERIES), 'data');
        self::assertSame([[self::FIRST_NAME], [self::COMPANY], []], $this->linkages($paged));

        $counted = $this->rows($this->read('/api/campaign-templates?sort=name&withCount=placeholders&relatedQuery[placeholders][filter][target]=' . self::HEADER, self::QUERIES), 'data');
        self::assertSame([1, 1, 0], $this->totals($counted));
    }

    #[Test]
    public function aPivotRowFilterIsUnrecognisedOnTheRelatedTypesOwnCollection(): void
    {
        $this->get('/api/placeholder-definitions?filter[unused]=1', ['Accept' => self::MEDIA_TYPE])->assertStatus(400);
    }

    /**
     * @param list<string> $targets
     */
    private function link(int $template, string $definition, ?string $override, array $targets): void
    {
        $row = new PkTemplatePlaceholder(['template_id' => $template, 'definition_id' => self::bytes($definition), 'default_override' => $override]);
        $row->save();
        $row->targets()->attach(\array_map(self::bytes(...), $targets));
    }

    private static function bytes(string $uuid): string
    {
        $bytes = \hex2bin(\str_replace('-', '', $uuid));
        \assert(\is_string($bytes));

        return $bytes;
    }

    /**
     * @return list<mixed>
     */
    private function related(int $template, string $query): array
    {
        return \array_column($this->rows($this->read('/api/campaign-templates/' . $template . '/placeholders?sort=name&' . $query), 'data'), 'id');
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<list<string>>
     */
    private function linkages(array $rows): array
    {
        return \array_map(static function (array $row): array {
            $relationships = $row['relationships'] ?? [];
            self::assertIsArray($relationships);
            $object = $relationships['placeholders'] ?? [];
            self::assertIsArray($object);
            $data = $object['data'] ?? null;
            self::assertIsArray($data);

            return \array_values(\array_map(static fn(mixed $member): string => \is_array($member) && \is_string($member['id'] ?? null) ? $member['id'] : '', $data));
        }, $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<mixed>
     */
    private function totals(array $rows): array
    {
        return \array_map(static function (array $row): mixed {
            $relationships = $row['relationships'] ?? [];
            self::assertIsArray($relationships);
            $object = $relationships['placeholders'] ?? [];
            self::assertIsArray($object);
            $meta = $object['meta'] ?? [];
            self::assertIsArray($meta);

            return $meta['total'] ?? null;
        }, $rows);
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
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri, string $accept = self::MEDIA_TYPE): TestResponse
    {
        $response = $this->get($uri, ['Accept' => $accept]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }
}
