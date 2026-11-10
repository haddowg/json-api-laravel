<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters\ThroughFiltersServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see ThroughFilterConformanceTestCase} against the reference Eloquent provider over
 * in-memory SQLite, plus the shape of the SQL a list through a path issues.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentThroughFilterConformanceTest extends ThroughFilterConformanceTestCase
{
    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aListIsOneExistsWithAnInNotOnePerValue(): void
    {
        $select = $this->leadSelect('/api/leads?filter[template]=tpl-1,tpl-2,tpl-3');

        self::assertSame(1, \substr_count($select, 'exists'));
        self::assertSame(1, \substr_count($select, ' in (?, ?, ?)'));
        self::assertStringContainsString('"tf_templates"."id" in (?, ?, ?)', $select);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aMultiHopListNestsOneExistsPerHopWithOneIn(): void
    {
        $select = $this->leadSelect('/api/leads?filter[advertiser]=1,2,3');

        self::assertSame(2, \substr_count($select, 'exists'));
        self::assertSame(1, \substr_count($select, ' in (?, ?, ?)'));
        self::assertStringContainsString('"tf_advertisers"."id" in (?, ?, ?)', $select);
    }

    #[Test]
    #[Group('spec:profiles')]
    public function aWithCountCorrelatesTheListOnTheCountedMembers(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->leadTotals('withCount=leads&relatedQuery[leads][filter][usesTemplate]=tpl-1,tpl-2');
        DB::disableQueryLog();

        $counts = [];
        foreach (DB::getQueryLog() as $entry) {
            $sql = \strtolower($entry['query']);
            if (\str_contains($sql, 'count(*)')) {
                $counts[] = $sql;
            }
        }

        self::assertCount(1, $counts);
        self::assertStringContainsString('(select count(*) from "tf_leads" where "tf_campaigns"."id" = "tf_leads"."campaign_id" and exists (select * from "tf_templates"', $counts[0]);
        self::assertStringContainsString('where "tf_leads"."id" = "tf_lead_template"."lead_id" and "tf_templates"."id" in (?, ?)', $counts[0]);
    }

    protected function store(): string
    {
        return 'eloquent';
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
        Schema::create('tf_advertisers', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('tf_brands', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
        });
        Schema::create('tf_templates', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('tf_campaigns', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('advertiser_id');
            $table->uuid('brand_id');
        });
        Schema::create('tf_leads', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('status');
            $table->unsignedInteger('campaign_id');
        });
        Schema::create('tf_lead_template', static function (Blueprint $table): void {
            $table->unsignedInteger('lead_id');
            $table->unsignedInteger('template_id');
        });

        foreach (ThroughFiltersServiceProvider::advertisers() as $id => $name) {
            DB::table('tf_advertisers')->insert(['id' => $id, 'name' => $name]);
        }
        foreach (ThroughFiltersServiceProvider::brands() as $id => $name) {
            DB::table('tf_brands')->insert(['id' => $id, 'name' => $name]);
        }
        foreach (ThroughFiltersServiceProvider::templates() as $id => $name) {
            DB::table('tf_templates')->insert(['id' => $id, 'name' => $name]);
        }
        foreach (ThroughFiltersServiceProvider::campaigns() as $row) {
            DB::table('tf_campaigns')->insert(['id' => $row['id'], 'name' => $row['name'], 'advertiser_id' => $row['advertiser'], 'brand_id' => $row['brand']]);
        }
        foreach (ThroughFiltersServiceProvider::leads() as $row) {
            DB::table('tf_leads')->insert(['id' => $row['id'], 'status' => $row['status'], 'campaign_id' => $row['campaign']]);
            foreach ($row['templates'] as $template) {
                DB::table('tf_lead_template')->insert(['lead_id' => $row['id'], 'template_id' => $template]);
            }
        }
    }

    /**
     * The one query that selects the leads `$path` answers with, lower-cased.
     */
    private function leadSelect(string $path): string
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->request($path)->assertOk();
        DB::disableQueryLog();

        $selects = [];
        foreach (DB::getQueryLog() as $entry) {
            $sql = \strtolower($entry['query']);
            if (\str_starts_with($sql, 'select "tf_leads".*') || \str_starts_with($sql, 'select * from "tf_leads"')) {
                $selects[] = $sql;
            }
        }

        self::assertCount(1, $selects, 'one query selects the leads');

        return $selects[0];
    }
}
