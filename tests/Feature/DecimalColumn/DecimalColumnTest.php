<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\DecimalColumn;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\DecimalColumn\DecimalColumnServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\DecimalColumn\Price;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * A `Decimal` written to a decimal column a model keeps as a string. Eloquent calls a
 * mutator without strict types, so the float a `Decimal` hydrates is coerced to a
 * string, and a `decimal:2` cast reads the column back as the same decimal string the
 * Symfony bundle stores for a Doctrine one.
 *
 * @internal
 */
#[CoversNothing]
final class DecimalColumnTest extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, DecimalColumnServiceProvider::class];
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
        Price::$savedRates = [];
        Schema::create('dc_prices', static function (Blueprint $table): void {
            $table->increments('id');
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('rate', 10, 2)->nullable();
        });
    }

    #[Test]
    public function aDecimalCastAndAStringMutatorBothStoreTheDecimalString(): void
    {
        $this->create('{"price":19.99,"rate":19.99}')->assertCreated();

        self::assertSame(['19.99'], Price::$savedRates);
        self::assertSame('19.99', Price::query()->findOrFail(1)->price);
    }

    #[Test]
    public function theDecimalCastReadsBackAtItsScale(): void
    {
        $this->create('{"price":20}')->assertCreated();
        $this->create('{"price":1.5}')->assertCreated();

        self::assertSame('20.00', Price::query()->findOrFail(1)->price);
        self::assertSame('1.50', Price::query()->findOrFail(2)->price);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function create(string $attributes): TestResponse
    {
        return $this->call(
            'POST',
            '/api/prices',
            server: ['HTTP_ACCEPT' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE],
            content: '{"data":{"type":"prices","attributes":' . $attributes . '}}',
        );
    }
}
