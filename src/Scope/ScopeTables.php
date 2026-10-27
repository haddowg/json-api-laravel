<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Scope;

use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;
use haddowg\JsonApi\Scope\ScopeCatalogInterface;
use haddowg\JsonApi\Scope\ScopeTable;
use haddowg\JsonApi\Scope\ScopeTableBuilder;
use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use Illuminate\Contracts\Cache\Repository;

/**
 * Each server's resolved {@see ScopeTable}: the scopes every operation needs, which the
 * enforcer checks and the OpenAPI document and the operation catalogue list.
 *
 * A table comes from the discovery snapshot when the app booted from one, since
 * `jsonapi:optimize` writes the tables into it. Without a snapshot it comes from the cache
 * store, keyed by a fingerprint of the discovered classes and their files, so editing a
 * resource builds a new one. Failing both it is built with core's {@see ScopeTableBuilder}
 * and stored. A build fails with a `\LogicException` when the resolver throws or an override
 * names an operation the type does not have.
 */
final class ScopeTables
{
    public const string DEFAULT_SCHEME = 'oauth2';

    private const string CACHE_KEY = 'jsonapi.scope_tables';

    /** @var array<string, ScopeTable> */
    private array $tables = [];

    private ?string $fingerprint = null;

    /**
     * @param \Closure(string): ServerMetadataInterface $metadata    server name → that server's metadata
     * @param list<string>                              $serverNames the declared server names, `default` first
     * @param string                                    $scheme      the security scheme the scopes are listed under (`jsonapi.openapi.security.scope_scheme`)
     */
    public function __construct(
        private readonly Discovery $discovery,
        private readonly \Closure $metadata,
        private readonly array $serverNames = [ServerRegistry::DEFAULT_SERVER],
        private readonly ?OperationScopeResolverInterface $resolver = null,
        private readonly ?ScopeCatalogInterface $catalog = null,
        private readonly string $scheme = self::DEFAULT_SCHEME,
        private readonly ?Repository $cache = null,
    ) {}

    /**
     * The table `$server` enforces and documents.
     *
     * @throws \LogicException when the table cannot be built
     */
    public function tableFor(string $server): ScopeTable
    {
        if (isset($this->tables[$server])) {
            return $this->tables[$server];
        }

        $table = $this->discovery->scopeTable($server) ?? $this->cached($server);
        if ($table === null) {
            $table = $this->build($server);
            $this->store($server, $table);
        }

        return $this->tables[$server] = $table;
    }

    /**
     * Every server's table merged, for the combined document. Two servers never share a
     * type there, so their entries do not collide; the Atomic Operations entry is the
     * `default` server's, as the combined document's other server-wide members are.
     */
    public function combined(): ScopeTable
    {
        $types = [];
        foreach ($this->serverNames as $server) {
            $types += $this->tableFor($server)->types;
        }

        return new ScopeTable($types, $this->tableFor(ServerRegistry::DEFAULT_SERVER)->atomic);
    }

    /**
     * Builds every server's table afresh, ignoring the snapshot and the cache store, and
     * keeps the result for the rest of the process. `jsonapi:optimize` calls it so a
     * resolver error fails the deploy and the snapshot it writes holds current tables.
     *
     * @return array<string, ScopeTable>
     *
     * @throws \LogicException when a table cannot be built
     */
    public function rebuild(): array
    {
        $this->fingerprint = null;
        $tables = [];
        foreach ($this->serverNames as $server) {
            $tables[$server] = $this->build($server);
            $this->store($server, $tables[$server]);
        }

        return $this->tables = $tables;
    }

    /**
     * Forgets the tables kept in the cache store.
     */
    public function clear(): void
    {
        $this->tables = [];
        $this->cache?->forget(self::CACHE_KEY);
    }

    /**
     * A builder for `$server` that reads the server's metadata only when it has to resolve
     * an operation, which the enforcer does for an entry the table lacks.
     */
    public function builderFor(string $server): ScopeTableBuilder
    {
        return new ScopeTableBuilder(
            new LazyServerMetadata(fn(): ServerMetadataInterface => ($this->metadata)($server)),
            $this->resolver,
        );
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    /**
     * @return array<string, string>
     */
    public function catalog(): array
    {
        return $this->catalog?->all() ?? [];
    }

    private function build(string $server): ScopeTable
    {
        return (new ScopeTableBuilder(($this->metadata)($server), $this->resolver))->build();
    }

    private function cached(string $server): ?ScopeTable
    {
        if ($this->cache === null) {
            return null;
        }

        $entry = $this->cache->get(self::CACHE_KEY);
        if (!\is_array($entry) || ($entry['fingerprint'] ?? null) !== $this->fingerprint()) {
            return null;
        }

        $tables = $entry['tables'] ?? null;
        $table = \is_array($tables) ? ($tables[$server] ?? null) : null;
        if (!\is_array($table)) {
            return null;
        }

        try {
            return ScopeTable::fromArray($table);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function store(string $server, ScopeTable $table): void
    {
        if ($this->cache === null || $this->discovery->loadedFromSnapshot()) {
            return;
        }

        $fingerprint = $this->fingerprint();
        $entry = $this->cache->get(self::CACHE_KEY);
        $tables = \is_array($entry) && ($entry['fingerprint'] ?? null) === $fingerprint && \is_array($entry['tables'] ?? null)
            ? $entry['tables']
            : [];
        $tables[$server] = $table->toArray();

        $this->cache->forever(self::CACHE_KEY, ['fingerprint' => $fingerprint, 'tables' => $tables]);
    }

    /**
     * What a table depends on: the discovered declarations, the files that declare them,
     * the resolver's class and file, and the server names.
     */
    private function fingerprint(): string
    {
        if ($this->fingerprint !== null) {
            return $this->fingerprint;
        }

        $classes = [];
        $declarations = [];
        foreach ($this->discovery->resources() as $descriptor) {
            $classes[] = $descriptor->class;
            $declarations[] = $descriptor->toArray();
        }
        foreach ($this->discovery->serializers() as $descriptor) {
            $classes[] = $descriptor->class;
            $declarations[] = $descriptor->toArray();
        }
        foreach ($this->discovery->actions() as $descriptor) {
            $classes[] = $descriptor->handlerClass;
            $declarations[] = $descriptor->toArray();
        }
        if ($this->resolver !== null) {
            $classes[] = $this->resolver::class;
        }

        $files = [];
        foreach (\array_unique($classes) as $class) {
            $file = \class_exists($class) ? (new \ReflectionClass($class))->getFileName() : false;
            $files[$class] = $file !== false ? @\filemtime($file) : null;
        }

        return $this->fingerprint = \md5(\serialize([
            $this->serverNames,
            $this->resolver !== null ? $this->resolver::class : null,
            $declarations,
            $files,
        ]));
    }
}
