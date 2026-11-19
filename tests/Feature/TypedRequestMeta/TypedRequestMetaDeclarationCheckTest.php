<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\TypedRequestMeta;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Routing\RouteRegistrar;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta\TypedRequestMetaServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every declared meta is read when the routes register and at `jsonapi:optimize`, so a
 * member declared twice, two additionalProperties entries, a getMeta() override beside
 * resourceMeta() and request meta on a raw-body action each fail the boot and the deploy.
 */
final class TypedRequestMetaDeclarationCheckTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function misdeclarations(): iterable
    {
        yield 'getMeta beside resourceMeta' => ['getmeta', 'declares resourceMeta() and overrides the deprecated getMeta()'];
        yield 'a member declared twice' => ['twice', 'declares the member "reason" twice'];
        yield 'two entries' => ['entries', 'more than one additionalProperties entry'];
        yield 'request meta on a raw body' => ['raw', 'takes a raw body but declares request meta'];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        $data = $this->providedData();
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('typed_request_meta.store', 'memory');
        $config->set('typed_request_meta.misdeclared', \is_string($data[0] ?? null) ? $data[0] : null);

        return [JsonApiServiceProvider::class, TypedRequestMetaServiceProvider::class];
    }

    #[Test]
    #[DataProvider('misdeclarations')]
    public function routeRegistrationRefusesIt(string $misdeclared, string $problem): void
    {
        $router = $this->resolve(Router::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($problem);

        $this->resolve(RouteRegistrar::class)->registerServer($router);
    }

    #[Test]
    #[DataProvider('misdeclarations')]
    public function theWarmerReportsIt(string $misdeclared, string $problem): void
    {
        $problems = \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm());

        self::assertStringContainsString($problem, $problems);
    }
}
