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
 * Pins the security projection (PLAN decisions 7 + 11): the package projects
 * `securedOperations` / `publicOperations` from the per-operation ability config on
 * `#[AsJsonApiResource]` — the Laravel translation of the bundle's Symfony
 * security-expression projection. The *document* must carry the same security/401 shape:
 * an operation with a declared Gate ability is secured (emits the configured security
 * requirement), one with the check disabled (`false`) is public (emits `security: []`).
 *
 * It reuses the authorization conformance wiring's secured `albums` type on the
 * auth-guarded `secure` server — `create` renamed to the `publish` ability (secured),
 * `delete` disabled (`false`, public).
 *
 * @internal
 */
final class OpenApiSecurityDocumentTest extends Orchestra
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

        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'secure' => ['prefix' => 'secure-api', 'middleware' => ['auth'], 'domain' => null],
        ]);

        $config->set('jsonapi.openapi.security', [
            'schemes' => [
                'bearerAuth' => ['type' => 'bearer', 'bearerFormat' => 'JWT'],
            ],
            'default_requirement' => [
                ['name' => 'bearerAuth'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function secureDocument(): array
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer('secure')->toArray();
        \assert(\array_is_list($doc) === false);

        return $doc;
    }

    #[Test]
    #[Group('openapi')]
    public function it_projects_the_configured_security_scheme_and_document_default(): void
    {
        $doc = $this->secureDocument();

        $schemes = $this->arrayAt($doc, 'components', 'securitySchemes');
        $this->assertArrayHasKey('bearerAuth', $schemes);
        $this->assertSame('http', $this->at($doc, 'components', 'securitySchemes', 'bearerAuth', 'type'));
        $this->assertNotEmpty($this->arrayAt($doc, 'security'));
    }

    #[Test]
    #[Group('openapi')]
    public function it_marks_an_ability_declared_operation_secured(): void
    {
        $post = $this->arrayAt($this->secureDocument(), 'paths', '/albums', 'post');

        // create → the `publish` ability (a declared string) ⇒ secured: the operation
        // carries the document security requirement.
        $this->assertArrayHasKey('security', $post);
        $this->assertNotEmpty($this->arrayAt($post, 'security'));
    }

    #[Test]
    #[Group('openapi')]
    public function it_marks_a_disabled_check_operation_public(): void
    {
        $delete = $this->arrayAt($this->secureDocument(), 'paths', '/albums/{id}', 'delete');

        // delete → `false` (check disabled) ⇒ public: the operation opts out of the
        // document default with the OAS "no auth" override `security: []`.
        $this->assertArrayHasKey('security', $delete);
        $this->assertSame([], $delete['security']);
    }

    #[Test]
    #[Group('openapi')]
    public function it_marks_a_policy_secured_operation_secured_without_a_per_op_ability(): void
    {
        // update carries NO per-operation ability, but the albums type declares a dedicated
        // `policy: AlbumApiPolicy::class`, so every exposed operation (minus the `false`
        // delete) is enforced at runtime — the document must mark it secured, not project it
        // as unsecured (the Phase-2 policy idiom, and a Phase-5 byte-compat risk).
        $patch = $this->arrayAt($this->secureDocument(), 'paths', '/albums/{id}', 'patch');

        $this->assertArrayHasKey('security', $patch);
        $this->assertNotEmpty($this->arrayAt($patch, 'security'));
    }
}
