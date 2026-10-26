<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\StoredUnique\StoredUniqueServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * `UniqueEntity`'s `using`, `errorPath` and `ignoreNull` options. Seed: active coupon SAVE10
 * in the EU, active SAVE20 with no region, archived SAVE30 in the EU.
 *
 * @internal
 */
#[CoversNothing]
final class UniqueEntityStoredRowsOptionsTest extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, StoredUniqueServiceProvider::class];
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
        Schema::create('su_coupons', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code');
            $table->string('region')->nullable();
            $table->boolean('archived')->default(false);
        });

        DB::table('su_coupons')->insert([
            ['id' => 1, 'code' => 'SAVE10', 'region' => 'EU', 'archived' => false],
            ['id' => 2, 'code' => 'SAVE20', 'region' => null, 'archived' => false],
            ['id' => 3, 'code' => 'SAVE30', 'region' => 'EU', 'archived' => true],
        ]);
    }

    #[Test]
    public function aDuplicateIsReportedOnTheErrorPath(): void
    {
        $this->create('SAVE10', 'EU')
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/region');

        $this->create('SAVE10', 'US')->assertCreated();
    }

    #[Test]
    public function theQueryScopeLeavesOtherRowsOut(): void
    {
        $this->create('SAVE30', 'EU')->assertCreated();
    }

    #[Test]
    public function aNullValueIsComparedWhenNullsAreNotIgnored(): void
    {
        $this->create('SAVE20', null)->assertStatus(422);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function create(string $code, ?string $region): TestResponse
    {
        $body = ['data' => ['type' => 'coupons', 'attributes' => ['code' => $code, 'region' => $region]]];

        return $this->call('POST', '/api/coupons', [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE], (string) \json_encode($body));
    }
}
