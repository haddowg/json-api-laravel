<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Event\ServingEvent;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use haddowg\JsonApiLaravel\Tests\Fixtures\PrefixedLinks\TeapotException;
use haddowg\JsonApiLaravel\Tests\Fixtures\PrefixedLinks\TeapotExceptionMapper;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * How a server's route prefix and `base_uri` combine into its links: an empty
 * base follows the prefix the route matched under, including a parameterised one and an
 * app served below the web root; a configured base, global or per server, is used as it
 * is; and an unprefixed server links from the origin alone, as before.
 *
 * @internal
 */
#[CoversNothing]
final class PrefixedServerLinksTest extends TestCase
{
    use InteractsWithJsonApi;

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function tenantPrefix($app): void
    {
        $this->servers($app, ['default' => ['prefix' => '{tenant}/api']]);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function unprefixed($app): void
    {
        $this->servers($app, ['default' => ['prefix' => '']]);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function perServerBase($app): void
    {
        $this->servers($app, ['default' => ['prefix' => 'api', 'base_uri' => 'https://api.example/api']]);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function globalBase($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.base_uri', 'https://global.example/api');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function perServerBaseOverridesGlobal($app): void
    {
        $this->globalBase($app);
        $this->perServerBase($app);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function teapotMapper($app): void
    {
        $app->tag([TeapotExceptionMapper::class], 'jsonapi.exception_mapper');
    }

    #[Test]
    #[Group('links')]
    #[DefineEnvironment('tenantPrefix')]
    public function aParameterisedPrefixIsFilledInFromTheRequest(): void
    {
        $response = $this->jsonApi()->withInclude('artist')->get('/acme/api/albums/1');

        $response->assertOk();
        $response->assertJsonPath('links.self.href', 'http://localhost/acme/api/albums/1?include=artist');
        $response->assertJsonPath('data.links.self.href', 'http://localhost/acme/api/albums/1');
        $response->assertJsonPath('data.relationships.artist.links.related.href', 'http://localhost/acme/api/albums/1/artist');
        $response->assertJsonPath('included.0.links.self.href', 'http://localhost/acme/api/artists/1');
    }

    #[Test]
    #[Group('links')]
    #[DefineEnvironment('unprefixed')]
    public function anUnprefixedServerLinksFromTheOriginAlone(): void
    {
        $response = $this->jsonApi()->get('/albums/1');

        $response->assertOk();
        $response->assertJsonPath('links.self.href', 'http://localhost/albums/1');
        $response->assertJsonPath('data.links.self.href', 'http://localhost/albums/1');
    }

    #[Test]
    #[Group('links')]
    #[DefineEnvironment('perServerBase')]
    public function aPerServerBaseIsUsedAsItIs(): void
    {
        $response = $this->jsonApi()->withPage(['number' => '1', 'size' => '1'])->get('/api/albums');

        $response->assertOk();
        $id = $response->json('data.0.id');
        self::assertIsString($id);
        $response->assertJsonPath('data.0.links.self.href', 'https://api.example/api/albums/' . $id);
        self::assertIsString($response->json('links.next.href'));
        self::assertStringStartsWith('https://api.example/api/albums?', $response->json('links.next.href'));
    }

    #[Test]
    #[Group('links')]
    #[DefineEnvironment('globalBase')]
    public function aServerWithoutItsOwnBaseInheritsTheGlobalOne(): void
    {
        $response = $this->jsonApi()->get('/api/albums/1');

        $response->assertOk();
        $response->assertJsonPath('links.self.href', 'https://global.example/api/albums/1');
        $response->assertJsonPath('data.links.self.href', 'https://global.example/api/albums/1');
    }

    #[Test]
    #[Group('links')]
    #[DefineEnvironment('perServerBaseOverridesGlobal')]
    public function aPerServerBaseOverridesTheGlobalOne(): void
    {
        $response = $this->jsonApi()->get('/api/albums/1');

        $response->assertOk();
        $response->assertJsonPath('links.self.href', 'https://api.example/api/albums/1');
        $response->assertJsonPath('data.links.self.href', 'https://api.example/api/albums/1');
    }

    #[Test]
    #[Group('links')]
    public function anAppBelowTheWebRootAddsItsBasePath(): void
    {
        $response = $this->call('GET', '/app/index.php/api/albums/1', [], [], [], [
            'SCRIPT_NAME' => '/app/index.php',
            'SCRIPT_FILENAME' => '/var/www/app/index.php',
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
        ]);

        $response->assertOk();
        $response->assertJsonPath('links.self.href', 'http://localhost/app/index.php/api/albums/1');
        $response->assertJsonPath('data.links.self.href', 'http://localhost/app/index.php/api/albums/1');
    }

    #[Test]
    #[Group('links')]
    #[DefineEnvironment('teapotMapper')]
    public function anErrorRenderedOnAPrefixedRouteIsBasedOnThePrefix(): void
    {
        Event::listen(ServingEvent::class, static function (): never {
            throw new TeapotException();
        });

        $response = $this->jsonApi()->get('/api/albums/1');

        $response->assertStatus(418);
        $response->assertJsonPath('errors.0.links.about', 'http://localhost/api/errors/teapot');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     * @param array<string, array<string, mixed>> $servers
     */
    private function servers($app, array $servers): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers', \array_map(
            static fn(array $server): array => $server + ['middleware' => [], 'domain' => null],
            $servers,
        ));
    }
}
