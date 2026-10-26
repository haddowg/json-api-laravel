<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\ExamplesInMemoryServiceProvider;

/**
 * `jsonapi.openapi.examples: false` leaves a server's document without operation examples,
 * and a server's own `openapi_examples` overrides it.
 *
 * @internal
 */
final class OpenApiExamplesServerSwitchTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, ExamplesInMemoryServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.openapi.examples', false);
        $config->set('jsonapi.servers.legacy', ['prefix' => 'legacy-api', 'middleware' => [], 'openapi_examples' => true]);
    }

    #[Test]
    public function the_global_switch_turns_examples_off(): void
    {
        $projection = $this->resolve(DocumentFactory::class)->projectionForServer('default');
        $doc = $projection->document->toArray();

        foreach ([['/widgets', 'get', '200'], ['/widgets/{id}', 'patch', '200'], ['/widgets/{id}/-actions/inspect', 'post', '200']] as [$path, $method, $status]) {
            $media = $this->arrayAt($doc, 'paths', $path, $method, 'responses', $status, 'content', 'application/vnd.api+json');
            self::assertArrayNotHasKey('example', $media, "{$method} {$path}");
        }
        self::assertSame([], $projection->missingExamples);
    }

    #[Test]
    public function a_servers_own_setting_wins(): void
    {
        $projection = $this->resolve(DocumentFactory::class)->projectionForServer('legacy');
        $media = $this->arrayAt($projection->document->toArray(), 'paths', '/gauges/{id}', 'get', 'responses', '200', 'content', 'application/vnd.api+json');

        self::assertArrayHasKey('example', $media);
        self::assertNotSame([], $projection->missingExamples);
    }
}
