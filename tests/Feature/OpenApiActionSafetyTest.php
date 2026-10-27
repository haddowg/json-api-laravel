<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Action\ActionDescriptor;
use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\ActionSafetyInMemoryServiceProvider;

/**
 * An action's declared safety and its own vendor extensions reach the OpenAPI document, and
 * an action answering to two methods gets one operation id per method and serves both.
 *
 * @internal
 */
final class OpenApiActionSafetyTest extends Orchestra
{
    use InteractsWithJsonApi;
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
            ActionSafetyInMemoryServiceProvider::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();
        \assert(\array_is_list($doc) === false);

        return $doc;
    }

    #[Test]
    public function declared_safety_projects_as_x_safety_with_only_the_declared_flags(): void
    {
        $doc = $this->document();

        foreach (['get', 'post'] as $method) {
            self::assertSame(
                ['readOnly' => true, 'idempotent' => true],
                $this->arrayAt($doc, 'paths', '/lamps/{id}/-actions/inspect', $method, 'x-safety'),
            );
        }

        self::assertSame(
            ['destructive' => true, 'idempotent' => true],
            $this->arrayAt($doc, 'paths', '/lamps/-actions/reset', 'post', 'x-safety'),
        );

        self::assertArrayNotHasKey('x-safety', $this->arrayAt($doc, 'paths', '/lamps/{id}/-actions/toggle', 'post'));
        self::assertArrayNotHasKey('x-safety', $this->arrayAt($doc, 'paths', '/lamps/{id}', 'get'));
    }

    #[Test]
    public function an_actions_own_extensions_merge_over_the_type_wide_set(): void
    {
        $doc = $this->document();

        $reset = $this->arrayAt($doc, 'paths', '/lamps/-actions/reset', 'post');
        self::assertSame('lamps', $reset['x-feature'] ?? null);
        self::assertSame('stable', $reset['x-stability'] ?? null);
        self::assertSame(['reset-lamps'], $reset['x-workflows'] ?? null);

        $toggle = $this->arrayAt($doc, 'paths', '/lamps/{id}/-actions/toggle', 'post');
        self::assertSame('beta', $toggle['x-stability'] ?? null);
        self::assertArrayNotHasKey('x-workflows', $toggle);
    }

    #[Test]
    public function a_multi_method_action_gets_one_operation_id_per_method(): void
    {
        $doc = $this->document();

        self::assertSame('action.lamps.inspect.get', $this->stringAt($doc, 'paths', '/lamps/{id}/-actions/inspect', 'get', 'operationId'));
        self::assertSame('action.lamps.inspect.post', $this->stringAt($doc, 'paths', '/lamps/{id}/-actions/inspect', 'post', 'operationId'));
        self::assertSame('action.lamps.reset', $this->stringAt($doc, 'paths', '/lamps/-actions/reset', 'post', 'operationId'));
    }

    #[Test]
    public function the_document_is_stamped_with_the_baseline_that_overtook_action_safety(): void
    {
        // Action safety is tier 15, but the shared links components raised the baseline to 18.
        self::assertSame(['contract' => 18], $this->arrayAt($this->document(), 'info', 'x-generator'));
    }

    #[Test]
    public function a_multi_method_action_serves_each_of_its_methods(): void
    {
        $this->jsonApi()->get('/api/lamps/1/-actions/inspect')
            ->assertOk()
            ->assertJsonPath('meta.method', 'GET');

        $this->jsonApi()->withDocument([])->post('/api/lamps/1/-actions/inspect')
            ->assertOk()
            ->assertJsonPath('meta.method', 'POST');
    }

    #[Test]
    public function the_safety_and_extensions_survive_the_discovery_snapshot(): void
    {
        $reset = null;
        foreach ($this->resolve(Discovery::class)->actions() as $candidate) {
            if ($candidate->path === 'reset') {
                $reset = $candidate;
            }
        }
        self::assertInstanceOf(ActionDescriptor::class, $reset);

        $file = \tempnam(\sys_get_temp_dir(), 'jsonapi-action');
        \assert(\is_string($file));
        \file_put_contents($file, '<?php return ' . \var_export($reset->toArray(), true) . ';');
        /** @var array{type: string, path: string, methods: list<string>, scope: string, input: string, inputType: string, outputType: string, responds: non-empty-list<array{kind: string, ref: string|null}>, handlerClass: class-string<\haddowg\JsonApiLaravel\Action\ActionHandlerInterface>, server: string, readOnly?: ?bool, destructive?: ?bool, idempotent?: ?bool, extensions?: array<string, mixed>} $data */
        $data = require $file;
        \unlink($file);
        $restored = ActionDescriptor::fromArray($data);

        self::assertNull($restored->readOnly);
        self::assertTrue($restored->destructive);
        self::assertTrue($restored->idempotent);
        self::assertSame(['x-stability' => 'stable', 'x-workflows' => ['reset-lamps']], $restored->extensions);
    }
}
