<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\OpenApiServe;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\HostServersDecorator;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\ObjectExample;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\ObjectExampleDecorator;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\UnkeyedDecorator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A document whose values hold an application object: without the class in
 * `serve_allowed_classes` the warmer skips the serve artifact with a warning and a
 * decorated request builds the document; with it the artifact is written and used.
 *
 * @internal
 */
final class OpenApiServeAllowedClassesTest extends OpenApiServeTestCase
{
    protected static function serveDecorators(): array
    {
        return ['openapi' => [HostServersDecorator::class, UnkeyedDecorator::class], 'schemas' => []];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app->tag([ObjectExampleDecorator::class], JsonApiServiceProvider::OPENAPI_FACTORY_TAG);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function allowObjectExample($app): void
    {
        $config = $app->make(Repository::class);
        \assert($config instanceof Repository);
        $config->set('jsonapi.openapi.serve_allowed_classes', [ObjectExample::class]);
    }

    #[Test]
    #[Group('openapi')]
    public function an_object_outside_the_allow_list_skips_the_artifact_with_a_warning(): void
    {
        $log = Log::spy();

        $this->warm();

        $this->assertFileExists($this->cacheDir . '/default.json');
        $this->assertFileDoesNotExist($this->cacheDir . '/default.serve');
        $this->assertNull($this->store()->readServeArtifact('default'));
        $log->shouldHaveReceived('warning')->once()->withArgs(
            static fn(string $message, array $context): bool => \str_contains($message, ObjectExample::class)
                && \str_contains($message, 'serve_allowed_classes')
                && ($context['class'] ?? null) === ObjectExample::class,
        );

        $response = $this->fetch('/docs.json', 'one.test');
        $response->assertOk();
        $this->assertValidOpenApi($response);
        $this->assertSame('kept', $this->arr($this->arr($this->arr($this->document($response)['components'] ?? null)['schemas'] ?? null)['ObjectExample'] ?? null)['example'] ?? null);
        $this->assertSame('Served to one.test', $this->arr($this->document($response)['info'] ?? null)['description'] ?? null);
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('allowObjectExample')]
    public function an_allowed_class_keeps_the_serve_artifact(): void
    {
        $this->warm();

        $artifact = $this->store()->readServeArtifact('default');
        $this->assertNotNull($artifact);
        $this->assertStringContainsString('"example":"kept"', $artifact->document->toJsonString());

        $response = $this->fetch('/docs.json', 'one.test');
        $response->assertOk();
        $this->assertSame('kept', $this->arr($this->arr($this->arr($this->document($response)['components'] ?? null)['schemas'] ?? null)['ObjectExample'] ?? null)['example'] ?? null);
    }
}
