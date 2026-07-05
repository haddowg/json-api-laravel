<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

/**
 * The shared filesystem contract between the {@see DocumentWarmer} (which writes the
 * pre-built OpenAPI documents at `artisan optimize`) and the OpenAPI controllers (which
 * serve them) — PLAN decision 11.
 *
 * It owns the **one** stable directory both sides agree on (resolved from the app's
 * cache path), so the controller serves the warmer's artifact with an `O(file read)`
 * (never a per-request build). A per-server JSON document lives at
 * `<cacheDir>/<server>.json`; the aggregate JSON Schema at
 * `<cacheDir>/json-schema/<server>.json` and the per-type schemas under
 * `<cacheDir>/json-schema/<server>/<type>.json`.
 *
 * This is a pure path/IO helper (no projection): the factories build the documents,
 * this stores and loads their serialized form.
 */
final class ArtifactStore
{
    public function __construct(private readonly string $cacheDir) {}

    /**
     * The absolute path of the pre-built OpenAPI JSON document for `$server`.
     */
    public function documentPath(string $server): string
    {
        return $this->baseDir() . '/' . $this->safe($server) . '.json';
    }

    /**
     * The pre-built document's JSON string, or null when the warmer has not (yet)
     * written it — the controller's signal to lazy-build via the {@see DocumentFactory}.
     */
    public function read(string $server): ?string
    {
        return $this->get($this->documentPath($server));
    }

    /**
     * Writes `$json` as the pre-built document for `$server`, creating the artifact
     * directory if needed.
     */
    public function write(string $server, string $json): void
    {
        $this->put($this->documentPath($server), $json);
    }

    /**
     * The absolute path of a per-type standalone JSON Schema artifact for
     * `(server, type)`.
     */
    public function schemaPath(string $server, string $type): string
    {
        return $this->baseDir() . '/json-schema/' . $this->safe($server) . '/' . $this->safe($type) . '.json';
    }

    /**
     * Writes a per-type standalone JSON Schema artifact.
     */
    public function writeSchema(string $server, string $type, string $json): void
    {
        $this->put($this->schemaPath($server, $type), $json);
    }

    /**
     * The absolute path of the aggregate JSON Schema artifact for `$server` — the one
     * object keyed by type the {@see \haddowg\JsonApiLaravel\Http\JsonSchemaController}
     * serves. It sits beside the per-type `<server>/` directory (`<server>.json` vs the
     * `<server>` dir), so the two never collide.
     */
    public function schemaAggregatePath(string $server): string
    {
        return $this->baseDir() . '/json-schema/' . $this->safe($server) . '.json';
    }

    /**
     * The pre-built aggregate JSON Schema document for `$server`, or null when the warmer
     * has not (yet) written it — the controller's signal to lazy-build.
     */
    public function readSchemaAggregate(string $server): ?string
    {
        return $this->get($this->schemaAggregatePath($server));
    }

    /**
     * Writes the aggregate JSON Schema document (one object keyed by type) for `$server`.
     */
    public function writeSchemaAggregate(string $server, string $json): void
    {
        $this->put($this->schemaAggregatePath($server), $json);
    }

    /**
     * Removes every artifact under the store's base directory — the `jsonapi:clear` /
     * `optimize:clear` hook. A missing directory is a no-op.
     */
    public function clear(): void
    {
        $base = $this->baseDir();
        if (!\is_dir($base)) {
            return;
        }

        $this->removeTree($base);
    }

    private function baseDir(): string
    {
        return \rtrim($this->cacheDir, '/');
    }

    private function get(string $path): ?string
    {
        if (!\is_file($path)) {
            return null;
        }

        $contents = \file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private function put(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0o777, true) && !\is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Could not create the OpenAPI artifact directory "%s".', $dir));
        }

        if (\file_put_contents($path, $contents) === false) {
            throw new \RuntimeException(\sprintf('Could not write the OpenAPI artifact "%s".', $path));
        }
    }

    private function removeTree(string $dir): void
    {
        $entries = \scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (\is_dir($path)) {
                $this->removeTree($path);
                @\rmdir($path);

                continue;
            }

            @\unlink($path);
        }
    }

    private function safe(string $name): string
    {
        return self::sanitizeSegment($name);
    }

    /**
     * Sanitises a server / type name for use as a single path segment (it comes from
     * trusted config / registered types, but a defensive whitelist keeps the artifact
     * tree flat and prevents any traversal). Shared with the JSON-Schema export command
     * so a CLI directory dump names its files by the same rule the warmer/controller use.
     */
    public static function sanitizeSegment(string $name): string
    {
        $safe = \preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        return ($safe === null || $safe === '') ? '_' : $safe;
    }
}
