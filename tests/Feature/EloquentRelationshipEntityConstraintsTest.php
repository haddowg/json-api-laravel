<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\RelationshipEntityConstraints\Company;
use haddowg\JsonApiLaravel\Tests\Fixtures\RelationshipEntityConstraints\EloquentServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\RelationshipEntityConstraints\IntegrationUser;
use haddowg\JsonApiLaravel\Tests\Fixtures\RelationshipEntityConstraints\Team;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * @internal
 */
#[CoversNothing]
final class EloquentRelationshipEntityConstraintsTest extends RelationshipEntityConstraintsTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            EloquentServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
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
        Schema::create('rec_companies', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('rec_integration_users', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('primary_company_id')->nullable();
        });
        Schema::create('rec_integration_user_company', static function (Blueprint $table): void {
            $table->unsignedInteger('integration_user_id');
            $table->unsignedInteger('company_id');
        });
        Schema::create('rec_teams', static function (Blueprint $table): void {
            $table->increments('id');
        });
        Schema::create('rec_team_company', static function (Blueprint $table): void {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('company_id');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Acme', 'Globex', 'Initech'] as $index => $name) {
            Company::query()->create(['id' => $index + 1, 'name' => $name]);
        }
        IntegrationUser::query()->create(['id' => 1, 'name' => 'Ada', 'primary_company_id' => 1])->companies()->attach([1]);
        IntegrationUser::query()->create(['id' => 2, 'name' => 'A stored name too long', 'primary_company_id' => 1])->companies()->attach([1, 2]);
        Team::query()->create(['id' => 1])->companies()->attach([1]);
    }
}
