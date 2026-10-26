<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\App\MusicCatalog\Support\CatalogConfig;

/**
 * `reject_read_only_attributes` resolves per server: the `admin` server turns it on while the
 * `default` server inherits the global off, and each server's OpenAPI error catalogue lists
 * the refusal only where it applies.
 *
 * @internal
 */
#[CoversNothing]
final class ReadOnlyRejectionServerConfigTest extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, MusicCatalogEloquentServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        CatalogConfig::apply($config);
        $config->set('jsonapi.servers.admin.reject_read_only_attributes', true);
    }

    #[Test]
    public function eachServerTakesItsOwnSetting(): void
    {
        $servers = $this->app?->make(ServerRegistry::class);
        self::assertInstanceOf(ServerRegistry::class, $servers);

        self::assertFalse($servers->get('default')->rejectsReadOnlyAttributes());
        self::assertTrue($servers->get('admin')->rejectsReadOnlyAttributes());
    }

    #[Test]
    public function onlyTheRefusingServersCatalogueListsTheCode(): void
    {
        $documents = $this->app?->make(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $documents);

        self::assertStringNotContainsString('ATTRIBUTE_WRITE_PROHIBITED', $documents->forServer('default')->toJsonString());
        self::assertStringContainsString('ATTRIBUTE_WRITE_PROHIBITED', $documents->forServer('admin')->toJsonString());
    }

    #[Test]
    public function aValueThatIsNotABooleanFailsTheBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jsonapi.servers.admin.reject_read_only_attributes');

        \haddowg\JsonApiLaravel\Server\ReadOnlyAttributesConfig::assertValid(null, ['admin' => ['reject_read_only_attributes' => 'yes']]);
    }
}
