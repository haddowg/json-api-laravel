<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel;

use haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface;
use haddowg\JsonApiLaravel\DataProvider\DataProviderInterface;

/**
 * The mutable registration surface behind the {@see \haddowg\JsonApiLaravel\Facades\JsonApi}
 * facade: the escape hatches an application (or the Testbench workbench) calls from a
 * service provider's `register()` to extend discovery, register data providers /
 * persisters explicitly, and opt out of automatic route registration.
 *
 * The service provider reads this manager when it builds the discovery, the
 * provider/persister registries, and the routes — so every call here must happen
 * before the provider's `boot()` (i.e. in a `register()`), which the provider ordering
 * guarantees.
 */
final class JsonApiManager
{
    /**
     * @var list<string>
     */
    private array $paths = [];

    /**
     * @var list<class-string>
     */
    private array $classes = [];

    /**
     * @var list<array{provider: DataProviderInterface<object>|class-string<DataProviderInterface<object>>, priority: int}>
     */
    private array $providers = [];

    /**
     * @var list<array{persister: DataPersisterInterface|class-string<DataPersisterInterface>, priority: int}>
     */
    private array $persisters = [];

    private bool $registerRoutes = true;

    /**
     * Adds one or more directories to the discovery scan (on top of the configured
     * `jsonapi.discovery.paths`).
     *
     * @param string|list<string> $paths
     */
    public function discover(string|array $paths): self
    {
        foreach ((array) $paths as $path) {
            $this->paths[] = $path;
        }

        return $this;
    }

    /**
     * Registers one or more capability classes explicitly, without a filesystem scan
     * — the escape hatch for a resource that lives outside the scanned paths.
     *
     * @param class-string|list<class-string> $classes
     */
    public function register(string|array $classes): self
    {
        foreach ((array) $classes as $class) {
            $this->classes[] = $class;
        }

        return $this;
    }

    /**
     * Registers a data provider (an instance, or a container-resolvable class-string)
     * at the given priority — higher priorities win the first-`supports()` match. The
     * reference Eloquent provider registers at the lowest priority so an application
     * provider (default `0`) shadows it for the types it serves.
     *
     * @param DataProviderInterface<object>|class-string<DataProviderInterface<object>> $provider
     */
    public function provider(DataProviderInterface|string $provider, int $priority = 0): self
    {
        $this->providers[] = ['provider' => $provider, 'priority' => $priority];

        return $this;
    }

    /**
     * Registers a data persister (an instance, or a container-resolvable class-string)
     * at the given priority — the write twin of {@see provider()}.
     *
     * @param DataPersisterInterface|class-string<DataPersisterInterface> $persister
     */
    public function persister(DataPersisterInterface|string $persister, int $priority = 0): self
    {
        $this->persisters[] = ['persister' => $persister, 'priority' => $priority];

        return $this;
    }

    /**
     * Suppresses automatic route registration, so routes are placed manually via the
     * `Route::jsonApi()` macro instead.
     */
    public function ignoreRoutes(): self
    {
        $this->registerRoutes = false;

        return $this;
    }

    /**
     * Whether the service provider should auto-register routes.
     */
    public function shouldRegisterRoutes(): bool
    {
        return $this->registerRoutes;
    }

    /**
     * The extra discovery paths registered via {@see discover()}.
     *
     * @return list<string>
     */
    public function discoveryPaths(): array
    {
        return $this->paths;
    }

    /**
     * The explicitly-registered capability classes.
     *
     * @return list<class-string>
     */
    public function registeredClasses(): array
    {
        return $this->classes;
    }

    /**
     * The explicitly-registered data-provider registrations.
     *
     * @return list<array{provider: DataProviderInterface<object>|class-string<DataProviderInterface<object>>, priority: int}>
     */
    public function providerRegistrations(): array
    {
        return $this->providers;
    }

    /**
     * The explicitly-registered data-persister registrations.
     *
     * @return list<array{persister: DataPersisterInterface|class-string<DataPersisterInterface>, priority: int}>
     */
    public function persisterRegistrations(): array
    {
        return $this->persisters;
    }
}
