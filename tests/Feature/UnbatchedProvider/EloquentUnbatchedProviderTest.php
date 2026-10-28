<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\UnbatchedProvider;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see UnbatchedProviderTestCase} over Eloquent models.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentUnbatchedProviderTest extends UnbatchedProviderTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }

    protected function storedOwnerOf(string $id): ?string
    {
        $ownerId = DB::table('ub_advertisers')->where('id', $id)->value('owner_id');

        return \is_scalar($ownerId) ? (string) $ownerId : null;
    }

    protected function storedCampaignsOf(string $id): array
    {
        return \array_values(\array_map(
            static fn(mixed $campaignId): string => \is_scalar($campaignId) ? (string) $campaignId : '',
            DB::table('ub_campaigns')->where('advertiser_id', $id)->orderBy('id')->pluck('id')->all(),
        ));
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
        Schema::create('ub_owners', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('ub_advertisers', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('owner_id')->nullable();
        });
        Schema::create('ub_campaigns', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('advertiser_id');
        });

        DB::table('ub_owners')->insert([['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Grace']]);
        DB::table('ub_advertisers')->insert([
            ['id' => 1, 'name' => 'Acme', 'owner_id' => 1],
            ['id' => 2, 'name' => 'Globex', 'owner_id' => 2],
        ]);
        DB::table('ub_campaigns')->insert([
            ['id' => 1, 'title' => 'Spring', 'advertiser_id' => 1],
            ['id' => 2, 'title' => 'Summer', 'advertiser_id' => 1],
            ['id' => 3, 'title' => 'Autumn', 'advertiser_id' => 2],
        ]);
    }
}
