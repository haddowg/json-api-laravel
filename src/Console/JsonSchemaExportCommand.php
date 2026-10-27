<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Console;

use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\OpenApi\JsonSchemaFactory;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Console\Command;

/**
 * Exports a server's standalone per-type JSON Schema 2020-12 documents — the resource
 * object (`type` const, `id`, `attributes`, …) for one type to a file / stdout, or every
 * type to a directory (PLAN decision 11).
 *
 * `--type` exports one type (stdout, or `--output=FILE`); omitting it exports every type
 * to `--output=DIR` (one `<type>.json` per type). The schema is the **same** core
 * {@see \haddowg\JsonApi\OpenApi\SchemaProjector} projection the OpenAPI document uses,
 * so the standalone artifact and the in-document component agree.
 *
 * `--compact` writes each document on one line, with the same encoding as the pretty default.
 */
final class JsonSchemaExportCommand extends Command
{
    protected $signature = 'jsonapi:jsonschema:export
        {--server= : The JSON:API server name to export (default: the "default" server)}
        {--type= : Export only this JSON:API type (default: every type)}
        {--output= : Write to this file (single type) or directory (all types) instead of stdout}
        {--compact : Write the JSON on one line instead of pretty-printing it}';

    protected $description = 'Export a server\'s per-type JSON Schema 2020-12 documents to a file, directory, or stdout.';

    public function handle(JsonSchemaFactory $schemas): int
    {
        $serverOption = $this->option('server');
        $server = \is_string($serverOption) && $serverOption !== '' ? $serverOption : ServerRegistry::DEFAULT_SERVER;
        $type = $this->option('type');
        $type = \is_string($type) && $type !== '' ? $type : null;
        $outputPath = $this->option('output');
        $outputPath = \is_string($outputPath) && $outputPath !== '' ? $outputPath : null;
        $compact = (bool) $this->option('compact');

        try {
            return $type !== null
                ? $this->exportOne($schemas, $server, $type, $outputPath, $compact)
                : $this->exportAll($schemas, $server, $outputPath, $compact);
        } catch (\Throwable $e) {
            $this->error(\sprintf('Could not export JSON Schema for server "%s": %s', $server, $e->getMessage()));

            return self::FAILURE;
        }
    }

    private function exportOne(JsonSchemaFactory $schemas, string $server, string $type, ?string $outputPath, bool $compact): int
    {
        $json = $this->encode($schemas->forType($type, $server), $compact);

        if ($outputPath === null) {
            $this->output->write($json . "\n");

            return self::SUCCESS;
        }

        if (\file_put_contents($outputPath, $json) === false) {
            $this->error(\sprintf('Could not write to "%s".', $outputPath));

            return self::FAILURE;
        }

        $this->info(\sprintf('Wrote the JSON Schema for "%s" to %s.', $type, $outputPath));

        return self::SUCCESS;
    }

    private function exportAll(JsonSchemaFactory $schemas, string $server, ?string $outputPath, bool $compact): int
    {
        $documents = $schemas->forServer($server);

        if ($outputPath === null) {
            // No directory: emit a single JSON object keyed by type so stdout stays one
            // well-formed document.
            $this->output->write($this->encode((object) $documents, $compact) . "\n");

            return self::SUCCESS;
        }

        if (!\is_dir($outputPath) && !@\mkdir($outputPath, 0o777, true) && !\is_dir($outputPath)) {
            $this->error(\sprintf('Could not create the output directory "%s".', $outputPath));

            return self::FAILURE;
        }

        foreach ($documents as $type => $document) {
            // Reuse the artifact store's path-segment sanitiser so a type carrying a
            // separator can't escape the output directory.
            $file = \rtrim($outputPath, '/') . '/' . ArtifactStore::sanitizeSegment((string) $type) . '.json';
            if (\file_put_contents($file, $this->encode($document, $compact)) === false) {
                $this->error(\sprintf('Could not write to "%s".', $file));

                return self::FAILURE;
            }
        }

        $this->info(\sprintf('Wrote %d JSON Schema document(s) for server "%s" to %s.', \count($documents), $server, $outputPath));

        return self::SUCCESS;
    }

    private function encode(\stdClass $document, bool $compact): string
    {
        $flags = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

        return (string) \json_encode($document, $compact ? $flags : $flags | \JSON_PRETTY_PRINT);
    }
}
