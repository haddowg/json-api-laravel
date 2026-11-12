<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\ErrorScope\ErrorScopeServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ErrorScope\Scanned\AlbumLocked;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * An application's error offered only on the operations that raise it. A discovered
 * error keeps the scope its own descriptor declares, and a `ScopedErrorSource` bound under
 * {@see JsonApiServiceProvider::ERROR_SOURCE_TAG} catalogues and scopes a class whose
 * descriptor declares none. Each covered operation gets its own inline response for the
 * code's status, whose error document is named for the operation; every other operation
 * keeps the shared one.
 *
 * @internal
 */
final class ErrorScopeTest extends TestCase
{
    use InteractsWithOpenApiDocument;

    #[Test]
    #[Group('openapi')]
    public function a_discovered_error_is_offered_only_on_the_operations_its_descriptor_scopes(): void
    {
        $document = $this->document();

        $this->assertContains('ALBUM_LOCKED', $this->offeredCodes($document, '/albums/{id}', 'patch', '409'));
        $this->assertInlineErrorDocument($document, '/albums/{id}', 'patch', '409', 'AlbumsUpdate409');

        // The album create has a 409 document of its own too, and it leaves this code out.
        $this->assertNotContains('ALBUM_LOCKED', $this->offeredCodes($document, '/albums', 'post', '409'));
        $this->assertInlineErrorDocument($document, '/albums', 'post', '409', 'AlbumsCreate409');
    }

    #[Test]
    #[Group('openapi')]
    public function a_tagged_scoped_source_scopes_an_error_whose_descriptor_declares_none(): void
    {
        $document = $this->document();

        // Catalogued by the tagged source alone, which no scan reaches.
        $this->assertNotNull($this->errorShapeFor($this->arrayAt($document, 'components', 'schemas'), 'PAYMENT_REQUIRED'));

        // The album delete gains the 402 the scoped code needs, offering it…
        $this->assertContains('PAYMENT_REQUIRED', $this->offeredCodes($document, '/albums/{id}', 'delete', '402'));
        $this->assertInlineErrorDocument($document, '/albums/{id}', 'delete', '402', 'AlbumsDelete402');

        // …and no operation the scope does not cover advertises it.
        foreach ($this->arrayAt($document, 'paths') as $path => $item) {
            foreach ($this->arrayAt($item) as $method => $operation) {
                if ($path === '/albums/{id}' && $method === 'delete') {
                    continue;
                }
                $responses = \is_array($operation) ? ($operation['responses'] ?? []) : [];
                $this->assertIsArray($responses);
                $this->assertArrayNotHasKey('402', $responses, \sprintf('%s %s offers the 402', $method, $path));
            }
        }
    }

    #[Test]
    #[Group('openapi')]
    public function a_tagged_source_is_read_at_runtime_and_never_snapshotted(): void
    {
        $cacheFile = \sys_get_temp_dir() . '/jsonapi-error-scope-' . \uniqid() . '.php';
        config(['jsonapi.discovery.cache' => $cacheFile]);
        config(['jsonapi.openapi.cache_path' => \sys_get_temp_dir() . '/jsonapi-artifacts-' . \uniqid()]);

        try {
            $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(0);

            // The snapshot holds the discovered class alone; the tagged source is a runtime
            // object the container hands over each time.
            $cached = new Discovery($this->resolve(DiscoveryScanner::class), [], [], $cacheFile);
            $this->assertSame([AlbumLocked::class], $cached->errors());

            $artifact = $this->resolve(ArtifactStore::class)->read('default');
            $this->assertIsString($artifact);
            $document = \json_decode($artifact, true);
            $this->assertIsArray($document);
            $this->assertContains('ALBUM_LOCKED', $this->offeredCodes($document, '/albums/{id}', 'patch', '409'));
            $this->assertContains('PAYMENT_REQUIRED', $this->offeredCodes($document, '/albums/{id}', 'delete', '402'));
        } finally {
            $this->resolve(ArtifactStore::class)->clear();
            if (\is_file($cacheFile)) {
                @\unlink($cacheFile);
            }
        }
    }

    /**
     * The projected document of the default server.
     *
     * @return array<array-key, mixed>
     */
    private function document(): array
    {
        return $this->resolve(DocumentFactory::class)->forServer()->toArray();
    }

    /**
     * The codes the `$status` response of `$method $path` offers: the `const` branches of
     * every shape its error document's `errors.items` names. The response is read inline,
     * or from the component it references.
     *
     * @param array<array-key, mixed> $document
     *
     * @return list<mixed>
     */
    private function offeredCodes(array $document, string $path, string $method, string $status): array
    {
        $response = $this->arrayAt($document, 'paths', $path, $method, 'responses', $status);
        if (isset($response['$ref'])) {
            $response = $this->componentAt($document, $this->stringAt($response, '$ref'));
        }
        $errorDocument = $this->componentAt($document, $this->stringAt($response, 'content', 'application/vnd.api+json', 'schema', '$ref'));

        $codes = [];
        foreach ($this->arrayAt($errorDocument, 'properties', 'errors', 'items', 'anyOf') as $branch) {
            $shape = $this->componentAt($document, $this->stringAt($branch, '$ref'));
            if (!isset($shape['allOf'])) {
                continue; // the generic, open `Error`
            }
            foreach ($this->arrayAt($shape, 'allOf', 1, 'properties', 'code', 'anyOf') as $code) {
                $codes[] = $this->at($code, 'const');
            }
        }

        return $codes;
    }

    /**
     * Asserts the `$status` response of `$method $path` sits inline in the operation and
     * uses the error document named `$name`.
     *
     * @param array<array-key, mixed> $document
     */
    private function assertInlineErrorDocument(array $document, string $path, string $method, string $status, string $name): void
    {
        $response = $this->arrayAt($document, 'paths', $path, $method, 'responses', $status);
        $this->assertArrayNotHasKey('$ref', $response);
        $this->assertSame('#/components/schemas/' . $name, $this->stringAt($response, 'content', 'application/vnd.api+json', 'schema', '$ref'));
    }

    /**
     * The component a local `#/components/…` reference points at.
     *
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function componentAt(array $document, string $ref): array
    {
        $this->assertStringStartsWith('#/', $ref);

        return $this->arrayAt($document, ...\explode('/', \substr($ref, 2)));
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            WorkbenchServiceProvider::class,
            ErrorScopeServiceProvider::class,
        ];
    }
}
