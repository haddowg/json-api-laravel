<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\RelatedBatch;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\RelatedBatch\CountingProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\RelatedBatch\RelatedBatchServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Wires the runs fixture ({@see RelatedBatchServiceProvider}) over the store {@see store()}
 * names, with its related types' providers batching as {@see batches()} says.
 *
 * @internal
 */
abstract class RelatedBatchTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    public const string COUNTABLE = 'https://haddowg.github.io/json-api/profiles/countable/';

    public const string RELATIONSHIP_QUERIES = 'https://haddowg.github.io/json-api/profiles/relationship-queries/';

    /**
     * `eloquent` or `memory`.
     */
    abstract protected function store(): string;

    protected function batches(): bool
    {
        return true;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('related_batch.store', $this->store());
        $config->set('related_batch.batches', $this->batches());

        return [JsonApiServiceProvider::class, RelatedBatchServiceProvider::class];
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
        if ($this->store() !== 'eloquent') {
            return;
        }

        Schema::create('xb_runs', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        DB::table('xb_runs')->insert([['id' => 1, 'name' => 'Alpha'], ['id' => 2, 'name' => 'Beta'], ['id' => 3, 'name' => 'Gamma']]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function read(string $uri, string $accept = self::MEDIA_TYPE): TestResponse
    {
        $this->progresses()->reset();
        $this->advertisers()->reset();

        return $this->get($uri, ['Accept' => $accept])->assertOk();
    }

    protected function progresses(): CountingProvider
    {
        $provider = $this->app?->make('related_batch.progresses');
        self::assertInstanceOf(CountingProvider::class, $provider);

        return $provider;
    }

    protected function advertisers(): CountingProvider
    {
        $provider = $this->app?->make('related_batch.advertisers');
        self::assertInstanceOf(CountingProvider::class, $provider);

        return $provider;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return list<string>
     */
    protected function included(TestResponse $response): array
    {
        $included = $response->json('included') ?? [];
        self::assertIsArray($included);
        $keys = \array_map(
            static fn(mixed $resource): string => \is_array($resource) && \is_string($resource['type'] ?? null) && \is_string($resource['id'] ?? null)
                ? $resource['type'] . ':' . $resource['id']
                : '',
            $included,
        );
        \sort($keys);

        return $keys;
    }
}
