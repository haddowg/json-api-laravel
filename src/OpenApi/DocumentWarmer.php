<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Pre-builds the per-server OpenAPI documents (+ the per-type JSON Schemas and the
 * aggregate schema document the {@see \haddowg\JsonApiLaravel\Http\JsonSchemaController}
 * serves) at `artisan optimize` (every prod deploy) into the artifact store, so the
 * controllers serve an `O(file read)` artifact and never build per request (PLAN
 * decision 11).
 *
 * It is deliberately **optional / non-fatal**: a docs build failure (a misconfigured
 * resource, an exotic paginator) must never break a deploy, so {@see warm()} catches
 * per-server failures, logs them, and carries on. The controller's dev lazy-build is the
 * safety net when an artifact is missing.
 *
 * When `jsonapi.openapi.public_path` is set, the warmer **also** writes a fully static
 * `<public>/<server>.json` (and `.yaml` when `symfony/yaml` is installed) so a web
 * server / CDN can serve the document with zero PHP.
 *
 * In **combined** multi-server mode the warmer additionally builds the single combined
 * document spanning every server and stores it under {@see DocumentFactory::COMBINED_KEY}.
 */
final class DocumentWarmer
{
    /**
     * @param list<string> $servers    the declared server names (`jsonapi.servers` keys)
     * @param bool          $enabled    `jsonapi.openapi.enabled` — skip warming entirely when off
     * @param bool          $combined   `jsonapi.openapi.multi_server === combined` — also warm the combined document
     * @param ?string       $publicPath `jsonapi.openapi.public_path` — also emit a static file here when set
     */
    public function __construct(
        private readonly DocumentFactory $documents,
        private readonly JsonSchemaFactory $schemas,
        private readonly ArtifactStore $store,
        private readonly array $servers,
        private readonly bool $enabled = true,
        private readonly bool $combined = false,
        private readonly ?string $publicPath = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Warms every server's artifacts (and the combined document in combined mode),
     * returning the list of warning messages for any server whose build failed — never
     * throwing, so a docs failure cannot break `artisan optimize`.
     *
     * @return list<string> the per-server failure messages (empty on full success)
     */
    public function warm(): array
    {
        if (!$this->enabled) {
            return [];
        }

        $failures = [];

        foreach ($this->servers as $server) {
            try {
                $this->warmServer($server);
            } catch (\Throwable $e) {
                $message = \sprintf('Failed to warm the OpenAPI document for server "%s": %s', $server, $e->getMessage());
                $this->logger?->error($message, ['exception' => $e]);
                $failures[] = $message;
            }
        }

        if ($this->combined) {
            try {
                $this->warmCombined();
            } catch (\Throwable $e) {
                $message = \sprintf('Failed to warm the combined OpenAPI document: %s', $e->getMessage());
                $this->logger?->error($message, ['exception' => $e]);
                $failures[] = $message;
            }
        }

        return $failures;
    }

    private function warmServer(string $server): void
    {
        $document = $this->documents->forServer($server);
        $this->store->write($server, $document->toJsonString(true));

        $schemas = $this->schemas->forServer($server);
        foreach ($schemas as $type => $schema) {
            $this->store->writeSchema($server, (string) $type, $this->encodeSchemas($schema));
        }
        // The aggregate the JsonSchemaController serves: one object keyed by type.
        $aggregate = $this->encodeSchemas((object) $schemas);
        $this->store->writeSchemaAggregate($server, $aggregate);

        if ($this->publicPath !== null) {
            $this->writeStatic($server, $document);
            $this->writeStaticSchemas($server, $aggregate);
        }
    }

    private function warmCombined(): void
    {
        $document = $this->documents->combined();
        $this->store->write(DocumentFactory::COMBINED_KEY, $document->toJsonString(true));

        $aggregate = $this->encodeSchemas((object) $this->schemas->combined());
        $this->store->writeSchemaAggregate(DocumentFactory::COMBINED_KEY, $aggregate);

        if ($this->publicPath !== null) {
            // A stable static filename for the combined document (the combined key is a
            // bracketed token, so a fixed name keeps the static artifact CDN-friendly).
            $this->writeStatic('combined', $document);
            $this->writeStaticSchemas('combined', $aggregate);
        }
    }

    private function encodeSchemas(\stdClass $schemas): string
    {
        return (string) \json_encode($schemas, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
    }

    private function writeStaticSchemas(string $server, string $json): void
    {
        $base = $this->ensurePublicDir();

        \file_put_contents($base . '/' . $this->safe($server) . '.schemas.json', $json);
    }

    private function writeStatic(string $server, \haddowg\JsonApi\OpenApi\OpenApi $document): void
    {
        $base = $this->ensurePublicDir();

        \file_put_contents($base . '/' . $this->safe($server) . '.json', $document->toJsonString(true));

        // YAML is emitted only when symfony/yaml is installed (a soft, suggested
        // dependency, gated like the export command's --format=yaml).
        if (\class_exists(Yaml::class)) {
            $yaml = Yaml::dump($document->toArray(), 16, 2, Yaml::DUMP_OBJECT_AS_MAP);
            \file_put_contents($base . '/' . $this->safe($server) . '.yaml', $yaml);
        }
    }

    private function ensurePublicDir(): string
    {
        $base = \rtrim((string) $this->publicPath, '/');
        if (!\is_dir($base) && !@\mkdir($base, 0o777, true) && !\is_dir($base)) {
            throw new \RuntimeException(\sprintf('Could not create the OpenAPI public_path directory "%s".', $base));
        }

        return $base;
    }

    private function safe(string $name): string
    {
        return ArtifactStore::sanitizeSegment($name);
    }
}
