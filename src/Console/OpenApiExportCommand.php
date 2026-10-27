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
 * spanning every server (`multi_server: combined`). `--compact` writes the JSON on one
 * line with the same encoding as the pretty default; YAML has no compact form, so
 * `--compact` with `--format=yaml` is rejected.
 *
 * Each operation example the projector could not build, because a value it needed declares
 * no example, is printed as a warning on stderr, so a piped document stays clean, and so is
 * each OAuth scope an operation needs that neither the scope catalogue nor the scope scheme
 * declares. `--strict` turns either into a failure and writes no document. A scope scheme
 * that is not `oauth2` is only ever a warning.
 */
final class OpenApiExportCommand extends Command
{
    protected $signature = 'jsonapi:openapi:export
        {--server= : The JSON:API server name to export (default: the "default" server)}
        {--format=json : Output format: json or yaml}
        {--output= : Write to this file instead of stdout}
        {--combined : Export the single combined document spanning every server}
        {--compact : Write the JSON on one line instead of pretty-printing it (JSON only)}
        {--strict : Fail, writing no document, when any operation example is missing or any required scope is undeclared}';

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
        $compact = (bool) $this->option('compact');
        if ($isYaml && $compact) {
            $this->error('The --compact option only applies to JSON output; drop it or export with --format=json.');

            return self::INVALID;
        }

        if ($isYaml && !\class_exists(Yaml::class)) {
            $this->error('YAML export requires symfony/yaml; install it (composer require symfony/yaml) or export JSON.');

            return self::FAILURE;
        }

        try {
            $projection = $combined ? $documents->combinedProjection() : $documents->projectionForServer($server);
        } catch (\Throwable $e) {
            $this->error(\sprintf('Could not build the OpenAPI document for server "%s": %s', $server, $e->getMessage()));

            return self::FAILURE;
        }

        $errors = $this->output->getErrorStyle();
        foreach ($projection->missingExamples as $missing) {
            $errors->writeln(\sprintf('<comment>Warning: %s</comment>', $missing->message()));
        }
        foreach ($projection->undeclaredScopes as $undeclared) {
            $errors->writeln(\sprintf('<comment>Warning: %s</comment>', $undeclared->message()));
        }
        if ($projection->scopeScheme !== null) {
            $errors->writeln(\sprintf('<comment>Warning: %s</comment>', $projection->scopeScheme->message()));
        }

        if ((bool) $this->option('strict') && ($projection->missingExamples !== [] || $projection->undeclaredScopes !== [])) {
            if ($projection->missingExamples !== []) {
                $errors->writeln(\sprintf(
                    '<error>%d operation example(s) missing, and --strict fails the export on any.</error>',
                    \count($projection->missingExamples),
                ));
            }
            if ($projection->undeclaredScopes !== []) {
                $errors->writeln(\sprintf(
                    '<error>%d required scope(s) undeclared, and --strict fails the export on any.</error>',
                    \count($projection->undeclaredScopes),
                ));
            }

            return self::FAILURE;
        }

        $document = $projection->document;
        $rendered = $isYaml
            ? Yaml::dump($document->toArray(), 16, 2, Yaml::DUMP_OBJECT_AS_MAP)
            : $document->toJsonString(!$compact) . "\n";

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
