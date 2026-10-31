<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Authorization;

use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;

/**
 * Whether a type's single rows are guarded on a server: something other than the row's existence
 * decides whether a read may show it. That is an Eloquent global scope on its model, a provider
 * of its own that may scope or hide rows, or a `view` check that can refuse a row (ADR 0021).
 * Answers are kept per server and type for the life of the process.
 */
final class GuardedTypes
{
    /**
     * @var array<string, array<string, bool>>
     */
    private array $guarded = [];

    /**
     * @var array<string, bool>
     */
    private array $scoped = [];

    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly DataPersisterRegistry $persisters,
        private readonly Authorizer $authorizer,
    ) {}

    public function isGuarded(string $server, string $type): bool
    {
        return $this->guarded[$server][$type] ??= $this->decide($server, $type);
    }

    /**
     * Whether a read of `$type` may leave out a row that exists: an Eloquent global scope on its
     * model, or a provider of its own that may scope or hide rows. A `view` check doesn't count,
     * since it never filters a list. A type no provider serves has no rows to hide.
     */
    public function isScoped(string $type): bool
    {
        return $this->scoped[$type] ??= $this->providers->supportsType($type)
            && ($this->providers->scopingProviderFor($type) !== null || $this->hasGlobalScopes($type));
    }

    private function hasGlobalScopes(string $type): bool
    {
        $provider = $this->providers->forType($type);
        $class = $provider instanceof EloquentDataProvider ? $provider->modelClassFor($type) : null;

        return $class !== null && (new $class())->getGlobalScopes() !== [];
    }

    private function decide(string $server, string $type): bool
    {
        if (!$this->providers->supportsType($type) || $this->providers->scopingProviderFor($type) !== null) {
            return true;
        }

        $provider = $this->providers->forType($type);
        $class = null;
        if ($provider instanceof EloquentDataProvider) {
            $class = $provider->modelClassFor($type);
            if ($class !== null && (new $class())->getGlobalScopes() !== []) {
                return true;
            }
        } elseif ($provider instanceof InMemoryDataProvider) {
            $row = $provider->store()->all()[0] ?? null;
            $class = \is_object($row) ? $row::class : null;
        }

        if ($class === null && $this->persisters->supportsType($type)) {
            $class = $this->persisters->forType($type)->instantiate($type)::class;
        }

        return $this->authorizer->guardsRow($type, $class, $server);
    }
}
