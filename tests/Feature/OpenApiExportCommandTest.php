<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests the artisan export commands (PLAN decision 11): `jsonapi:openapi:export` and
 * `jsonapi:jsonschema:export`. They are always available (independent of the HTTP expose
 * gate), so CI can spec-diff / publish with no web exposure.
 *
 * @internal
 */
final class OpenApiExportCommandTest extends TestCase
{
    use InteractsWithOpenApiDocument;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = \sys_get_temp_dir() . '/jsonapi-export-' . \uniqid();
        \mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
        parent::tearDown();
    }

    #[Test]
    #[Group('openapi')]
    public function it_exports_the_openapi_document_to_a_file(): void
    {
        $file = $this->tmp . '/openapi.json';

        $this->jsonApiArtisan('jsonapi:openapi:export', ['--output' => $file])->assertExitCode(0);

        $decoded = \json_decode((string) \file_get_contents($file), true);
        $this->assertIsArray($decoded);
        $this->assertStringStartsWith('3.1', $this->stringAt($decoded, 'openapi'));
        $this->assertArrayHasKey('/albums', $this->arrayAt($decoded, 'paths'));
    }

    #[Test]
    #[Group('openapi')]
    public function it_rejects_an_unsupported_format(): void
    {
        $this->jsonApiArtisan('jsonapi:openapi:export', ['--format' => 'xml'])->assertExitCode(2);
    }

    #[Test]
    #[Group('openapi')]
    public function it_exports_one_type_json_schema_to_a_file(): void
    {
        $file = $this->tmp . '/albums.json';

        $this->jsonApiArtisan('jsonapi:jsonschema:export', ['--type' => 'albums', '--output' => $file])->assertExitCode(0);

        $decoded = \json_decode((string) \file_get_contents($file), true);
        $this->assertIsArray($decoded);
        $this->assertSame('urn:jsonapi:schema:albums', $this->at($decoded, '$id'));
    }

    #[Test]
    #[Group('openapi')]
    public function it_exports_every_type_json_schema_to_a_directory(): void
    {
        $this->jsonApiArtisan('jsonapi:jsonschema:export', ['--output' => $this->tmp])->assertExitCode(0);

        $this->assertFileExists($this->tmp . '/albums.json');
        $this->assertFileExists($this->tmp . '/artists.json');
        $this->assertFileExists($this->tmp . '/genres.json');
    }

    #[Test]
    #[Group('openapi')]
    public function it_fails_the_json_schema_export_for_an_unknown_type(): void
    {
        $this->jsonApiArtisan('jsonapi:jsonschema:export', ['--type' => 'nope'])->assertExitCode(1);
    }

    private function removeTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        foreach ((array) \scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || !\is_string($entry)) {
                continue;
            }

            $path = $dir . '/' . $entry;
            \is_dir($path) ? $this->removeTree($path) : @\unlink($path);
        }

        @\rmdir($dir);
    }
}
