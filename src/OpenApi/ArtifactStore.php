<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

use haddowg\JsonApi\OpenApi\Serve\EntityTag;
use haddowg\JsonApi\OpenApi\Serve\ServeArtifact;
use haddowg\JsonApi\OpenApi\Serve\ServeArtifactCodec;
use haddowg\JsonApi\OpenApi\Serve\UnserializableValue;

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
 * `<cacheDir>/json-schema/<server>/<type>.json`, and the operations map the `describedby`
 * resolver reads at `<cacheDir>/<server>.operations.json`, and the route serving each
 * document, when the app routes the controller itself, at
 * `<cacheDir>/document-routes.json`. Beside each served artifact sits a
 * `.hash` file (the served ETag), and beside the document a `.serve` file holding it as
 * serialized value objects for the serve decorators, encoded by core's
 * {@see ServeArtifactCodec} (ADR 0036).
 *
 * This is a pure path/IO helper (no projection): the factories build the documents,
 * this stores and loads their serialized form.
 */
final class ArtifactStore
{
    /**
     * This package's Composer name, which the serve artifact fingerprint records beside core's.
     */
    public const string PACKAGE = 'haddowg/json-api-laravel';

    private readonly ServeArtifactCodec $codec;

    public function __construct(
        private readonly string $cacheDir,
        ?ServeArtifactCodec $codec = null,
    ) {
        $this->codec = $codec ?? new ServeArtifactCodec([self::PACKAGE]);
    }

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
     * The operations the warmed document carries, as path template to lower-case methods:
     * the input of the `describedby` resolver, so a request never loads the whole document
     * to point a link at it (ADR 0037). Null when it was never written or can't be read.
     *
     * @return array<string, list<string>>|null
     */
    public function readOperations(string $server): ?array
    {
        $json = $this->get($this->operationsPath($server));
        if ($json === null) {
            return null;
        }

        try {
            $operations = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($operations)) {
            return null;
        }

        $map = [];
        foreach ($operations as $path => $methods) {
            if (!\is_string($path) || !\is_array($methods)) {
                return null;
            }
            $map[$path] = \array_values(\array_filter($methods, \is_string(...)));
        }

        return $map;
    }

    /**
     * @param array<string, list<string>> $operations
     */
    public function writeOperations(string $server, array $operations): void
    {
        $this->put($this->operationsPath($server), \json_encode((object) $operations, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * The route serving each server's document, as the {@see DocumentRouteLocator} found
     * it, or null when the map was never written or can't be read.
     *
     * @return array<string, array{route: string, parameters: array<string, string>}|null>|null
     */
    public function readDocumentRoutes(): ?array
    {
        $json = $this->get($this->documentRoutesPath());
        if ($json === null) {
            return null;
        }

        try {
            $routes = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($routes)) {
            return null;
        }

        $map = [];
        foreach ($routes as $server => $route) {
            if (!\is_string($server)) {
                return null;
            }
            if ($route === null) {
                $map[$server] = null;

                continue;
            }
            if (!\is_array($route) || !\is_string($route['route'] ?? null) || !\is_array($route['parameters'] ?? null)) {
                return null;
            }
            $map[$server] = [
                'route' => $route['route'],
                'parameters' => \array_filter($route['parameters'], \is_string(...)),
            ];
        }

        /** @var array<string, array{route: string, parameters: array<string, string>}|null> $map */
        return $map;
    }

    /**
     * @param array<string, array{route: string, parameters: array<string, string>}|null> $routes
     */
    public function writeDocumentRoutes(array $routes): void
    {
        $encoded = \array_map(static fn(?array $route): ?array => $route === null ? null : ['route' => $route['route'], 'parameters' => (object) $route['parameters']], $routes);

        $this->put($this->documentRoutesPath(), \json_encode((object) $encoded, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * The hash of the document artifact, written beside it so a conditional request costs
     * one small read. Null when it was never written.
     */
    public function readHash(string $server): ?string
    {
        return $this->get($this->baseDir() . '/' . $this->safe($server) . '.hash');
    }

    /**
     * Writes the document artifact and its hash together, returning the hash.
     */
    public function writeWithHash(string $server, string $json): string
    {
        $hash = EntityTag::hash($json);
        $this->write($server, $json);
        $this->put($this->baseDir() . '/' . $this->safe($server) . '.hash', $hash);

        return $hash;
    }

    /**
     * The warmed document as value objects (ADR 0036), or null when it was never written,
     * no longer decodes, or was written by another installed version of core or this package.
     */
    public function readServeArtifact(string $server): ?ServeArtifact
    {
        $bytes = $this->get($this->serveArtifactPath($server));

        return $bytes === null ? null : $this->codec->decode($bytes);
    }

    /**
     * @throws UnserializableValue when the document holds an object the codec can't allow back in
     */
    public function writeServeArtifact(string $server, ServeArtifact $artifact): void
    {
        $this->put($this->serveArtifactPath($server), $this->codec->encode($artifact));
    }

    /**
     * Removes `$server`'s serve artifact, so a document that could not be encoded is never
     * served from an older one.
     */
    public function forgetServeArtifact(string $server): void
    {
        $path = $this->serveArtifactPath($server);
        if (\is_file($path)) {
            @\unlink($path);
        }
    }

    /**
     * The hash of the aggregate JSON Schema artifact, or null when it was never written.
     */
    public function readSchemaAggregateHash(string $server): ?string
    {
        return $this->get($this->baseDir() . '/json-schema/' . $this->safe($server) . '.hash');
    }

    /**
     * Writes the aggregate JSON Schema artifact and its hash together, returning the hash.
     */
    public function writeSchemaAggregateWithHash(string $server, string $json): string
    {
        $hash = EntityTag::hash($json);
        $this->writeSchemaAggregate($server, $json);
        $this->put($this->baseDir() . '/json-schema/' . $this->safe($server) . '.hash', $hash);

        return $hash;
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

    private function documentRoutesPath(): string
    {
        return $this->baseDir() . '/document-routes.json';
    }

    private function operationsPath(string $server): string
    {
        return $this->baseDir() . '/' . $this->safe($server) . '.operations.json';
    }

    private function serveArtifactPath(string $server): string
    {
        return $this->baseDir() . '/' . $this->safe($server) . '.serve';
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
