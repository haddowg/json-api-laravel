<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\SecurityInMemoryServiceProvider;

/**
 * Multi-server OpenAPI tests (PLAN decision 11 / §7): each server projects its own
 * document scoped to the types assigned to it, and combined mode unions every server's
 * types into a single document. Reuses the two-server (default + secure) authorization
 * wiring — `genres` on `default`, `albums`/`artists` on `secure`.
 *
 * @internal
 */
final class OpenApiMultiServerTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            SecurityInMemoryServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('app.debug', true);
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'secure' => ['prefix' => 'secure-api', 'middleware' => [], 'domain' => null],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function server(string $name): array
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer($name)->toArray();
        \assert(\array_is_list($doc) === false);

        return $doc;
    }

    #[Test]
    #[Group('openapi')]
    public function each_server_projects_only_its_own_types(): void
    {
        $secure = $this->arrayAt($this->server('secure'), 'paths');
        $default = $this->arrayAt($this->server('default'), 'paths');

        $this->assertArrayHasKey('/albums', $secure);
        $this->assertArrayNotHasKey('/genres', $secure);

        $this->assertArrayHasKey('/genres', $default);
        $this->assertArrayNotHasKey('/albums', $default);
    }

    #[Test]
    #[Group('openapi')]
    public function the_secure_server_title_names_the_server(): void
    {
        $this->assertSame('JSON:API (secure)', $this->at($this->server('secure'), 'info', 'title'));
    }

    #[Test]
    #[Group('openapi')]
    public function combined_mode_unions_every_server_type(): void
    {
        $combined = $this->resolve(DocumentFactory::class)->combined()->toArray();

        $paths = $this->arrayAt($combined, 'paths');
        $this->assertArrayHasKey('/albums', $paths);
        $this->assertArrayHasKey('/artists', $paths);
        $this->assertArrayHasKey('/genres', $paths);
    }

    #[Test]
    #[Group('openapi')]
    public function it_serves_a_per_server_document_route(): void
    {
        $body = $this->get('/secure/docs.json')->assertOk()->getContent();
        $this->assertIsString($body);

        $decoded = \json_decode($body, true);
        $this->assertIsArray($decoded);
        $paths = $this->arrayAt($decoded, 'paths');
        $this->assertArrayHasKey('/albums', $paths);
        $this->assertArrayNotHasKey('/genres', $paths);
    }
}
