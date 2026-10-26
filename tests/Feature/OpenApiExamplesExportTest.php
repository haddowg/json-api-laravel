<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Workbench\App\Providers\ExamplesInMemoryServiceProvider;

/**
 * `jsonapi:openapi:export` prints each missing operation example as a warning on stderr,
 * leaving stdout to the document, and `--strict` fails on any.
 *
 * @internal
 */
final class OpenApiExamplesExportTest extends Orchestra
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
        $config->set('jsonapi.servers.legacy', ['prefix' => 'legacy-api', 'middleware' => []]);
    }

    #[Test]
    public function a_missing_example_is_a_warning(): void
    {
        $this->jsonApiArtisan('jsonapi:openapi:export', ['--server' => 'legacy'])
            ->expectsOutputToContain('Warning: fetchOne.gizmos has no example: "gizmos" declares no example for "colour".')
            ->assertExitCode(0);
    }

    #[Test]
    public function strict_fails_on_a_missing_example_and_writes_no_document(): void
    {
        $file = \sys_get_temp_dir() . '/jsonapi-strict-' . \uniqid() . '.json';

        $this->jsonApiArtisan('jsonapi:openapi:export', ['--server' => 'legacy', '--strict' => true, '--output' => $file])
            ->expectsOutputToContain('--strict fails the export')
            ->assertExitCode(1);

        self::assertFileDoesNotExist($file);
    }

    #[Test]
    public function strict_passes_when_no_example_is_missing(): void
    {
        // The default server's gauges type has no fields, so it passes only because its
        // fetch-one override replaces every part of the example.
        $this->jsonApiArtisan('jsonapi:openapi:export', ['--strict' => true])
            ->doesntExpectOutputToContain('Warning:')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_warnings_go_to_stderr_and_the_document_to_stdout(): void
    {
        $output = new class (self::memory()) extends StreamOutput implements ConsoleOutputInterface {
            private OutputInterface $stderr;

            /**
             * @param resource $stream
             */
            public function __construct($stream)
            {
                parent::__construct($stream);
                $this->stderr = new StreamOutput(OpenApiExamplesExportTest::memory());
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

        self::assertSame(0, Artisan::call('jsonapi:openapi:export', ['--server' => 'legacy'], $output));

        $stdout = self::contents($output);
        $stderr = $output->getErrorOutput();
        self::assertInstanceOf(StreamOutput::class, $stderr);

        self::assertJson($stdout);
        self::assertStringContainsString('Warning: fetchOne.gizmos has no example', self::contents($stderr));
    }

    /**
     * @return resource
     */
    public static function memory()
    {
        $stream = \fopen('php://memory', 'w+b');
        \assert($stream !== false);

        return $stream;
    }

    private static function contents(StreamOutput $output): string
    {
        $stream = $output->getStream();
        \rewind($stream);

        return (string) \stream_get_contents($stream);
    }
}
