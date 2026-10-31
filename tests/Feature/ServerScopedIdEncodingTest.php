<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedIds\ServerScopedIdsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Each server decodes a wire id through the encoder of its own resource class.
 * Only the Eloquent reference pair decodes ids, so there is no in-memory twin.
 *
 * @internal
 */
#[CoversNothing]
final class ServerScopedIdEncodingTest extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, ServerScopedIdsServiceProvider::class];
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
            'admin' => ['prefix' => 'admin-api', 'middleware' => [], 'domain' => null],
        ]);
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('ssi_parcels', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('label');
        });
        DB::table('ssi_parcels')->insert(['id' => 1, 'label' => 'Books']);
    }

    #[Test]
    public function eachServerDecodesIdsWithItsOwnClassesEncoder(): void
    {
        $this->get('/api/parcels/1', ['Accept' => self::MEDIA_TYPE])
            ->assertOk()->assertJsonPath('data.id', '1')->assertJsonPath('data.attributes.label', 'Books');
        $this->get('/admin-api/parcels/p-1', ['Accept' => self::MEDIA_TYPE])
            ->assertOk()->assertJsonPath('data.id', 'p-1')->assertJsonPath('data.attributes.label', 'Books');

        $this->get('/api/parcels/p-1', ['Accept' => self::MEDIA_TYPE])->assertNotFound();
        $this->get('/admin-api/parcels/1', ['Accept' => self::MEDIA_TYPE])->assertNotFound();
    }
}
