<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Workbench\App\Providers\CompositeInMemoryServiceProvider;

/**
 * `--compact` on the export commands. The composite types' schemas carry empty objects,
 * so the tests can check they stay `{}` in the one-line form.
 *
 * @internal
 */
final class CompactExportTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    private const FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = \sys_get_temp_dir() . '/jsonapi-compact-export-' . \uniqid();
        \mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (['pretty', 'compact', ''] as $sub) {
            $dir = \rtrim($this->tmp . '/' . $sub, '/');
            foreach ((array) \glob($dir . '/*.json') as $file) {
                @\unlink((string) $file);
            }
            @\rmdir($dir);
        }
        parent::tearDown();
    }

    #[Test]
    #[Group('openapi')]
    public function it_writes_compact_openapi_json_to_stdout(): void
    {
        $this->assertCompactFormOf(
            $this->stdout('jsonapi:openapi:export', []),
            $this->stdout('jsonapi:openapi:export', ['--compact' => true]),
            "\n",
        );
    }

    #[Test]
    #[Group('openapi')]
    public function it_writes_compact_openapi_json_to_a_file(): void
    {
        $file = $this->tmp . '/openapi.json';
        $this->jsonApiArtisan('jsonapi:openapi:export', ['--compact' => true, '--output' => $file])->assertExitCode(0);

        $this->assertCompactFormOf(
            $this->stdout('jsonapi:openapi:export', []),
            (string) \file_get_contents($file),
            "\n",
        );
    }

    #[Test]
    #[Group('openapi')]
    public function it_rejects_compact_yaml(): void
    {
        $this->jsonApiArtisan('jsonapi:openapi:export', ['--compact' => true, '--format' => 'yaml'])
            ->expectsOutputToContain('--compact')
            ->assertExitCode(2);
    }

    #[Test]
    #[Group('openapi')]
    public function it_writes_compact_json_schema_to_stdout(): void
    {
        $this->assertCompactFormOf(
            $this->stdout('jsonapi:jsonschema:export', ['--type' => 'composites']),
            $this->stdout('jsonapi:jsonschema:export', ['--type' => 'composites', '--compact' => true]),
            "\n",
        );
        $this->assertCompactFormOf(
            $this->stdout('jsonapi:jsonschema:export', []),
            $this->stdout('jsonapi:jsonschema:export', ['--compact' => true]),
            "\n",
        );
    }

    #[Test]
    #[Group('openapi')]
    public function it_writes_compact_json_schema_to_a_file(): void
    {
        $pretty = $this->tmp . '/pretty.json';
        $compact = $this->tmp . '/compact.json';
        $this->jsonApiArtisan('jsonapi:jsonschema:export', ['--type' => 'composites', '--output' => $pretty])->assertExitCode(0);
        $this->jsonApiArtisan('jsonapi:jsonschema:export', ['--type' => 'composites', '--output' => $compact, '--compact' => true])->assertExitCode(0);

        // A file export has no trailing newline in either form.
        $this->assertCompactFormOf((string) \file_get_contents($pretty), (string) \file_get_contents($compact), '');
    }

    #[Test]
    #[Group('openapi')]
    public function it_writes_compact_json_schema_to_a_directory(): void
    {
        $this->jsonApiArtisan('jsonapi:jsonschema:export', ['--output' => $this->tmp . '/pretty'])->assertExitCode(0);
        $this->jsonApiArtisan('jsonapi:jsonschema:export', ['--output' => $this->tmp . '/compact', '--compact' => true])->assertExitCode(0);

        foreach (['composites', 'composite-settings'] as $type) {
            $this->assertCompactFormOf(
                (string) \file_get_contents($this->tmp . '/pretty/' . $type . '.json'),
                (string) \file_get_contents($this->tmp . '/compact/' . $type . '.json'),
                '',
            );
        }
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, CompositeInMemoryServiceProvider::class];
    }

    /**
     * The compact document is the pretty one re-encoded without JSON_PRETTY_PRINT: the
     * same structure, empty objects still `{}`, one line, slashes unescaped.
     */
    private function assertCompactFormOf(string $pretty, string $compact, string $trailing): void
    {
        $structure = \json_decode($pretty, false, 512, \JSON_THROW_ON_ERROR);
        self::assertEquals($structure, \json_decode($compact, false, 512, \JSON_THROW_ON_ERROR));
        self::assertSame(\json_encode($structure, self::FLAGS) . $trailing, $compact);

        $body = $trailing === '' ? $compact : \substr($compact, 0, -\strlen($trailing));
        self::assertStringNotContainsString("\n", $body);
        self::assertStringStartsWith('{"', $body);
        self::assertStringContainsString('{}', $body);
        self::assertSame(\substr_count($pretty, '{}'), \substr_count($body, '{}'));
        self::assertStringContainsString('https://', $body);
        self::assertStringNotContainsString('\/', $body);
    }

    /**
     * Runs a command and returns only its stdout, so warnings on stderr stay out of the
     * document.
     *
     * @param array<string, mixed> $parameters
     */
    private function stdout(string $command, array $parameters): string
    {
        $output = new class extends BufferedOutput implements ConsoleOutputInterface {
            private OutputInterface $stderr;

            public function __construct()
            {
                parent::__construct();
                $this->stderr = new BufferedOutput();
            }

            public function getErrorOutput(): OutputInterface
            {
                return $this->stderr;
            }

            public function setErrorOutput(OutputInterface $error): void
            {
                $this->stderr = $error;
            }

            public function section(): \Symfony\Component\Console\Output\ConsoleSectionOutput
            {
                throw new \LogicException('Not used.');
            }
        };

        self::assertSame(0, Artisan::call($command, $parameters, $output));

        return $output->fetch();
    }
}
