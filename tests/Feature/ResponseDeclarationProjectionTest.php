<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\ResponseDeclInMemoryServiceProvider;

/**
 * Pins the per-operation response-declaration projection end-to-end from the attribute: a
 * `widgets` type declaring atomic response overrides projects each declared status onto its
 * operation — an async `202` (pollable job document + `Content-Location` + `Retry-After`)
 * alongside `201` on create, `204` alongside `200` on update, a meta-only `200` alongside
 * `204` on delete, and a `303` completion redirect alongside `200` on fetch-one.
 *
 * @internal
 */
final class ResponseDeclarationProjectionTest extends Orchestra
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
            ResponseDeclInMemoryServiceProvider::class,
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
    #[Group('async')]
    public function it_projects_the_declared_create_responses_including_an_async_202(): void
    {
        $responses = $this->arrayAt($this->document(), 'paths', '/widgets', 'post', 'responses');

        self::assertArrayHasKey('201', $responses);
        self::assertArrayHasKey('202', $responses);

        // The 202 async-accept advertises the pollable job document + the poll headers.
        $accepted = $this->arrayAt($responses, '202');
        self::assertArrayHasKey('Content-Location', $this->arrayAt($accepted, 'headers'));
        self::assertArrayHasKey('Retry-After', $this->arrayAt($accepted, 'headers'));
        $ref = $this->stringAt($accepted, 'content', 'application/vnd.api+json', 'schema', '$ref');
        self::assertStringContainsString('Document', $ref);
    }

    #[Test]
    #[Group('async')]
    public function it_projects_the_declared_update_delete_and_fetch_one_responses(): void
    {
        $doc = $this->document();

        $update = $this->arrayAt($doc, 'paths', '/widgets/{id}', 'patch', 'responses');
        self::assertArrayHasKey('200', $update);
        self::assertArrayHasKey('204', $update);

        $delete = $this->arrayAt($doc, 'paths', '/widgets/{id}', 'delete', 'responses');
        self::assertArrayHasKey('204', $delete);
        self::assertArrayHasKey('200', $delete);
        // The delete 200 is a meta-only document.
        $metaRef = $this->stringAt($delete, '200', 'content', 'application/vnd.api+json', 'schema', '$ref');
        self::assertStringContainsString('MetaDocument', $metaRef);

        $fetchOne = $this->arrayAt($doc, 'paths', '/widgets/{id}', 'get', 'responses');
        self::assertArrayHasKey('200', $fetchOne);
        self::assertArrayHasKey('303', $fetchOne);
        // The 303 completion redirect carries a Location header and no body.
        self::assertArrayHasKey('Location', $this->arrayAt($fetchOne, '303', 'headers'));
        self::assertArrayNotHasKey('content', $this->arrayAt($fetchOne, '303'));
    }
}
