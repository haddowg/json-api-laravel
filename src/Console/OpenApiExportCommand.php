<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Console;

use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Exports a server's OpenAPI 3.1 document to JSON or YAML — to a file, or to stdout
 * (PLAN decision 11). The CLI export is **always available** (independent of the HTTP
 * expose gate), so a CI pipeline can spec-diff or publish the document with no web
 * exposure.
 *
 * `--format=yaml` requires `symfony/yaml`; without it the command fails with a clear
 * message rather than emitting broken output. `--combined` exports the single document
 * spanning every server (`multi_server: combined`).
 */
final class OpenApiExportCommand extends Command
{
    protected $signature = 'jsonapi:openapi:export
        {--server= : The JSON:API server name to export (default: the "default" server)}
        {--format=json : Output format: json or yaml}
        {--output= : Write to this file instead of stdout}
        {--combined : Export the single combined document spanning every server}';

    protected $description = 'Export a server\'s OpenAPI 3.1 document (JSON or YAML) to a file or stdout.';

    public function handle(DocumentFactory $documents): int
    {
        $serverOption = $this->option('server');
        $server = \is_string($serverOption) && $serverOption !== '' ? $serverOption : ServerRegistry::DEFAULT_SERVER;
        $formatOption = $this->option('format');
        $format = \strtolower(\is_string($formatOption) ? $formatOption : 'json');
        $outputFile = $this->option('output');
        $outputFile = \is_string($outputFile) && $outputFile !== '' ? $outputFile : null;
        $combined = (bool) $this->option('combined');

        if (!\in_array($format, ['json', 'yaml', 'yml'], true)) {
            $this->error(\sprintf('Unsupported format "%s" (use json or yaml).', $format));

            return self::INVALID;
        }

        $isYaml = $format === 'yaml' || $format === 'yml';
        if ($isYaml && !\class_exists(Yaml::class)) {
            $this->error('YAML export requires symfony/yaml; install it (composer require symfony/yaml) or export JSON.');

            return self::FAILURE;
        }

        try {
            $document = $combined ? $documents->combined() : $documents->forServer($server);
        } catch (\Throwable $e) {
            $this->error(\sprintf('Could not build the OpenAPI document for server "%s": %s', $server, $e->getMessage()));

            return self::FAILURE;
        }

        $rendered = $isYaml
            ? Yaml::dump($document->toArray(), 16, 2, Yaml::DUMP_OBJECT_AS_MAP)
            : $document->toJsonString(true) . "\n";

        if ($outputFile === null) {
            // Write straight to the output stream (no styling) so a piped/redirected
            // document is byte-clean.
            $this->output->write($rendered);

            return self::SUCCESS;
        }

        if (\file_put_contents($outputFile, $rendered) === false) {
            $this->error(\sprintf('Could not write to "%s".', $outputFile));

            return self::FAILURE;
        }

        $this->info(\sprintf('Wrote the OpenAPI document for server "%s" to %s.', $server, $outputFile));

        return self::SUCCESS;
    }
}
