<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\SecurityInMemoryServiceProvider;

/**
 * The OpenAPI `servers` list: a server with an empty `base_uri` advertises its
 * route prefix as a relative URL, a parameterised prefix as a templated URL when the
 * route has a default for it, a configured base as it is, and `jsonapi.openapi.servers`
 * overrides all of it. Two servers: `default` (genres) under `api` and `secure`
 * (albums, artists) under `secure-api`.
 *
 * @internal
 */
#[CoversNothing]
final class OpenApiPrefixServersTest extends Orchestra
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
    protected function prefixed($app): void
    {
        $this->servers($app, [
            'default' => ['prefix' => 'api'],
            'secure' => ['prefix' => 'secure-api'],
        ]);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function combined($app): void
    {
        $this->prefixed($app);
        $this->config($app)->set('jsonapi.openapi.multi_server', 'combined');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function tenantPrefixWithDefault($app): void
    {
        $this->servers($app, [
            'default' => ['prefix' => '{tenant}/api', 'route_defaults' => ['tenant' => 'acme']],
            'secure' => ['prefix' => 'secure-api'],
        ]);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function tenantPrefixWithoutDefault($app): void
    {
        $this->servers($app, [
            'default' => ['prefix' => '{tenant}/api'],
            'secure' => ['prefix' => 'secure-api'],
        ]);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function configuredSecureBase($app): void
    {
        $this->servers($app, [
            'default' => ['prefix' => 'api'],
            'secure' => ['prefix' => 'secure-api', 'base_uri' => 'https://secure.example/secure-api'],
        ]);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function explicitServers($app): void
    {
        $this->prefixed($app);
        $this->config($app)->set('jsonapi.openapi.servers', [['url' => 'https://docs.example/v1']]);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function unprefixed($app): void
    {
        $this->servers($app, [
            'default' => ['prefix' => ''],
            'secure' => ['prefix' => 'secure-api'],
        ]);
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('prefixed')]
    public function eachServerAdvertisesItsOwnPrefix(): void
    {
        self::assertSame([['url' => '/api']], $this->serversOf('default'));
        self::assertSame([['url' => '/secure-api']], $this->serversOf('secure'));
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('combined')]
    public function theCombinedDocumentAdvertisesEveryServersPrefix(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->combined()->toArray();

        self::assertSame([['url' => '/api'], ['url' => '/secure-api']], $doc['servers'] ?? []);
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('tenantPrefixWithDefault')]
    public function aParameterisedPrefixBecomesAServerVariableWithTheRouteDefault(): void
    {
        self::assertSame(
            [['url' => '/{tenant}/api', 'variables' => ['tenant' => ['default' => 'acme']]]],
            $this->serversOf('default'),
        );
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('tenantPrefixWithoutDefault')]
    public function aParameterisedPrefixWithoutADefaultIsLeftOut(): void
    {
        self::assertSame([], $this->serversOf('default'));
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('configuredSecureBase')]
    public function aConfiguredBaseIsTheServerUrl(): void
    {
        self::assertSame([['url' => 'https://secure.example/secure-api']], $this->serversOf('secure'));
        self::assertSame([['url' => '/api']], $this->serversOf('default'));
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('explicitServers')]
    public function explicitOpenApiServersWin(): void
    {
        self::assertSame([['url' => 'https://docs.example/v1']], $this->serversOf('default'));
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('unprefixed')]
    public function anUnprefixedServerAdvertisesNoServers(): void
    {
        self::assertSame([], $this->serversOf('default'));
    }

    /**
     * @return array<mixed>
     */
    private function serversOf(string $server): array
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer($server)->toArray();
        $servers = $doc['servers'] ?? [];
        \assert(\is_array($servers));

        return $servers;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     * @param array<string, array<string, mixed>> $servers
     */
    private function servers($app, array $servers): void
    {
        $this->config($app)->set('jsonapi.servers', \array_map(
            static fn(array $server): array => $server + ['middleware' => [], 'domain' => null],
            $servers,
        ));
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    private function config($app): \Illuminate\Contracts\Config\Repository
    {
        $config = $app['config'];
        \assert($config instanceof \Illuminate\Contracts\Config\Repository);

        return $config;
    }
}
