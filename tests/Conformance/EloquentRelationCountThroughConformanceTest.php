<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\Tests\Fixtures\CountThrough\CountThroughServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see RelationCountThroughConformanceTestCase} against the reference Eloquent provider
 * over in-memory SQLite, where a count's subquery is built on the related model.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentRelationCountThroughConformanceTest extends RelationCountThroughConformanceTestCase
{
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
        foreach (['ct_brands', 'ct_templates'] as $table) {
            Schema::create($table, static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name');
            });
        }
        Schema::create('ct_campaigns', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('brand_id')->nullable();
        });
        Schema::create('ct_leads', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('campaign_id');
            $table->unsignedInteger('brand_id')->nullable();
        });
        Schema::create('ct_lead_template', static function (Blueprint $table): void {
            $table->unsignedInteger('lead_id');
            $table->unsignedInteger('template_id');
        });
        Schema::create('ct_campaign_shortlist', static function (Blueprint $table): void {
            $table->unsignedInteger('campaign_id');
            $table->unsignedInteger('lead_id');
        });

        foreach (CountThroughServiceProvider::brands() as $id => $name) {
            DB::table('ct_brands')->insert(['id' => $id, 'name' => $name]);
        }
        foreach (CountThroughServiceProvider::templates() as $id => $name) {
            DB::table('ct_templates')->insert(['id' => $id, 'name' => $name]);
        }
        foreach (CountThroughServiceProvider::campaigns() as $row) {
            DB::table('ct_campaigns')->insert(['id' => $row['id'], 'name' => $row['name'], 'brand_id' => $row['brand']]);
        }
        foreach (CountThroughServiceProvider::leads() as $row) {
            DB::table('ct_leads')->insert(['id' => $row['id'], 'name' => $row['name'], 'campaign_id' => $row['campaign'], 'brand_id' => $row['brand']]);
            foreach ($row['templates'] as $template) {
                DB::table('ct_lead_template')->insert(['lead_id' => $row['id'], 'template_id' => $template]);
            }
            foreach ($row['shortlistedBy'] as $campaign) {
                DB::table('ct_campaign_shortlist')->insert(['campaign_id' => $campaign, 'lead_id' => $row['id']]);
            }
        }
    }
}
