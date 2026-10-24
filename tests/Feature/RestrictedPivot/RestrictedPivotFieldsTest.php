<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\RestrictedPivot;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\RestrictedPivot\RestrictedPivotServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\EloquentWorkbenchServiceProvider;
use Workbench\Database\Seeders\ConformanceSeeder;

/**
 * A pivot field that is write-only or hidden for the request stays out of `meta.pivot` on
 * every response that renders it: the related endpoint, the relationship endpoint and the
 * relationship mutation echo. Playlist 1 holds tracks 1 to 4 (track N at weight N).
 *
 * @internal
 */
#[CoversNothing]
final class RestrictedPivotFieldsTest extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            EloquentWorkbenchServiceProvider::class,
            RestrictedPivotServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
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
        $this->loadMigrationsFrom(\dirname(__DIR__, 3) . '/workbench/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new ConformanceSeeder())->run();
    }

    public function test_the_related_endpoint_leaves_out_write_only_and_hidden_pivot_fields(): void
    {
        $response = $this->read('/api/mixtapes/1/orderedTracks')->assertOk();

        self::assertSame(['position' => 1], $this->pivotOf($response, '1'));
    }

    public function test_the_relationship_endpoint_leaves_out_write_only_and_hidden_pivot_fields(): void
    {
        $response = $this->read('/api/mixtapes/1/relationships/orderedTracks')->assertOk();

        self::assertSame(['position' => 1], $this->pivotOf($response, '1'));
        self::assertSame(['position' => 2], $this->pivotOf($response, '2'));
    }

    public function test_the_mutation_echo_leaves_out_a_write_only_pivot_field_it_just_wrote(): void
    {
        $response = $this->call('POST', '/api/mixtapes/2/relationships/orderedTracks', [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ], (string) \json_encode(['data' => [
            ['type' => 'tracks', 'id' => '4', 'meta' => ['pivot' => ['position' => 7, 'weight' => 9]]],
        ]]));

        $response->assertOk();
        self::assertSame(['position' => 7], $this->pivotOf($response, '4'));
    }

    public function test_a_hidden_predicate_is_evaluated_per_member(): void
    {
        $response = $this->read('/api/mixtapes/1/relationships/rankedTracks')->assertOk();

        self::assertSame(['position' => 1, 'weight' => 1, 'addedAt' => '2024-01-01T00:00:00+00:00'], $this->pivotOf($response, '1'));
        self::assertSame(['weight' => 2, 'addedAt' => '2024-01-02T00:00:00+00:00'], $this->pivotOf($response, '2'));
    }

    public function test_a_write_only_predicate_is_evaluated_per_request(): void
    {
        $shown = $this->read('/api/mixtapes/1/rankedTracks')->assertOk();
        self::assertSame(1, $this->pivotOf($shown, '1')['weight'] ?? null);

        $concealed = $this->read('/api/mixtapes/1/rankedTracks', ['X-Conceal-Weight' => '1'])->assertOk();
        self::assertSame(['position' => 1, 'addedAt' => '2024-01-01T00:00:00+00:00'], $this->pivotOf($concealed, '1'));
    }

    /**
     * @param array<string, string> $headers
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri, array $headers = []): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE, ...$headers]);
    }

    /**
     * The `meta.pivot` of the member with `$id`, or null when it renders none.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<array-key, mixed>|null
     */
    private function pivotOf(TestResponse $response, string $id): ?array
    {
        foreach ((array) $response->json('data') as $member) {
            if (\is_array($member) && ($member['id'] ?? null) === $id) {
                $pivot = \data_get($member, 'meta.pivot');

                return \is_array($pivot) ? $pivot : null;
            }
        }

        self::fail('No member ' . $id . ' in the response.');
    }
}
