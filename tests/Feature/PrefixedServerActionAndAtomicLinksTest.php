<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;
use Workbench\App\Providers\SurfaceInMemoryServiceProvider;

/**
 * A custom action and an Atomic Operations batch on a server under the `api` prefix with
 * an empty `base_uri` render their resource links under the prefix too (ADR 0028).
 *
 * @internal
 */
#[CoversNothing]
final class PrefixedServerActionAndAtomicLinksTest extends Orchestra
{
    use InteractsWithJsonApi;

    private const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            SurfaceInMemoryServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    #[Test]
    #[Group('links')]
    public function aCustomActionResponseCarriesThePrefix(): void
    {
        $this->actingAs(new User(['id' => 1, 'name' => 'Writer', 'can_write' => true, 'can_read' => true, 'is_admin' => false]));

        $this->jsonApi()
            ->withDocument(['data' => ['type' => 'albums', 'attributes' => ['status' => 'released']]])
            ->post('/api/albums/1/-actions/publish')
            ->assertOk()
            ->assertJsonPath('data.links.self', 'http://localhost/api/albums/1');
    }

    #[Test]
    #[Group('links')]
    public function anAtomicResultCarriesThePrefix(): void
    {
        $body = (string) \json_encode(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'artists', 'attributes' => ['name' => 'Aphex Twin', 'slug' => 'aphex-twin']]],
        ]]);

        $response = $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => self::ATOMIC_MEDIA_TYPE,
            'CONTENT_TYPE' => self::ATOMIC_MEDIA_TYPE,
        ], $body);

        $response->assertOk();
        $id = $response->json('atomic:results.0.data.id');
        self::assertIsString($id);
        $response->assertJsonPath('atomic:results.0.data.links.self', 'http://localhost/api/artists/' . $id);
    }
}
