<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\Authorization\Authorizer;
use haddowg\JsonApiLaravel\Authorization\ResourceAuthorization;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataProvider\DataProviderInterface;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\RelatedIncludeBatcher;
use haddowg\JsonApiLaravel\DataProvider\RelationCountBatcher;
use haddowg\JsonApiLaravel\DataProvider\RelationCriteriaFactory;
use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\Exception\ExceptionMapperInterface;
use haddowg\JsonApiLaravel\Exception\JsonApiExceptionRenderer;
use haddowg\JsonApiLaravel\Operation\CrudOperationHandler;
use haddowg\JsonApiLaravel\Operation\TargetResolver;
use haddowg\JsonApiLaravel\Routing\RouteRegistrar;
use haddowg\JsonApiLaravel\Serializer\RequestScopedRelationshipCount;
use haddowg\JsonApiLaravel\Server\ServerFactory;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use haddowg\JsonApiLaravel\Server\TypeMetadataResolver;
use haddowg\JsonApiLaravel\Validation\ConstraintTranslator;
use haddowg\JsonApiLaravel\Validation\ConstraintTranslatorInterface;
use haddowg\JsonApiLaravel\Validation\FilterValueValidator;
use haddowg\JsonApiLaravel\Validation\JsonPointerBuilder;
use haddowg\JsonApiLaravel\Validation\ResourceValidator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * The package service provider (auto-discovered via `extra.laravel.providers`). It
 * registers configuration, the discovery scanner, the provider/persister registries,
 * the per-server core {@see \haddowg\JsonApi\Server\Server} assembly, the invokable
 * controller's collaborators, the route-scoped exception renderer, and — in `boot()` —
 * the automatic route registration, the `Route::jsonApi()` macro, and the exception
 * renderable hookup. It also publishes the config file.
 */
final class JsonApiServiceProvider extends ServiceProvider
{
    /**
     * The container tag a {@see ConstraintTranslatorInterface} binding can carry to join
     * the always-on validation bridge's extension point (alongside `JsonApi::constraintTranslator()`
     * and discovery scanning).
     */
    public const string CONSTRAINT_TRANSLATOR_TAG = 'jsonapi.constraint_translator';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/jsonapi.php', 'jsonapi');

        $this->app->singleton(JsonApiManager::class);

        $this->registerPsrBridge();
        $this->registerDiscovery();
        $this->registerRegistries();
        $this->registerValidation();
        $this->registerAuthorization();
        $this->registerRelationships();
        $this->registerServers();

        $this->app->singleton(TargetResolver::class);
        $this->app->singleton(CrudOperationHandler::class);

        $this->registerExceptionRenderer();

        $this->app->singleton(RouteRegistrar::class, static function (Container $app): RouteRegistrar {
            /** @var array<string, array{prefix?: string, middleware?: list<string>|string, domain?: string|null}> $servers */
            $servers = config('jsonapi.servers', []);

            return new RouteRegistrar($app->make(Discovery::class), $servers);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/jsonapi.php' => $this->app->configPath('jsonapi.php'),
        ], 'jsonapi-config');

        $this->registerRouteMacro();

        /** @var JsonApiManager $manager */
        $manager = $this->app->make(JsonApiManager::class);
        // Skip auto-registration (and the discovery scan behind it) when the app is
        // serving from a compiled route cache — Laravel's own `loadRoutesFrom()` guards
        // registration the same way. Without this, every `route:cache`d request would
        // re-run discovery and re-add every route into the runtime collection (PLAN
        // decision 4: registration is route:cache-safe). Discovery stays lazy, so the
        // dispatch + exception-render paths still resolve it on first use.
        $routesAreCached = $this->app instanceof CachesRoutes && $this->app->routesAreCached();
        if ($manager->shouldRegisterRoutes() && !$routesAreCached) {
            /** @var RouteRegistrar $registrar */
            $registrar = $this->app->make(RouteRegistrar::class);
            /** @var Router $router */
            $router = $this->app->make('router');
            $registrar->registerConfiguredServers($router);
        }

        $this->registerExceptionRenderable();
    }

    /**
     * Binds the PSR-7 bridge: nyholm's factory (implements every PSR-17 factory
     * interface) drives both the Laravel→PSR-7 request conversion and the PSR-7
     * factories core's response rendering needs; the Symfony bridge converts requests
     * in and responses out.
     */
    private function registerPsrBridge(): void
    {
        $this->app->singleton(Psr17Factory::class);

        $this->app->singleton(PsrHttpFactory::class, static function (Container $app): PsrHttpFactory {
            $psr17 = $app->make(Psr17Factory::class);

            return new PsrHttpFactory($psr17, $psr17, $psr17, $psr17);
        });

        $this->app->singleton(HttpFoundationFactory::class);
    }

    private function registerDiscovery(): void
    {
        $this->app->singleton(DiscoveryScanner::class);

        $this->app->singleton(Discovery::class, static function (Container $app): Discovery {
            /** @var JsonApiManager $manager */
            $manager = $app->make(JsonApiManager::class);

            /** @var list<string> $configPaths */
            $configPaths = config('jsonapi.discovery.paths', []);
            /** @var string|null $cachePath */
            $cachePath = config('jsonapi.discovery.cache');

            return new Discovery(
                $app->make(DiscoveryScanner::class),
                [...$configPaths, ...$manager->discoveryPaths()],
                $manager->registeredClasses(),
                $cachePath,
            );
        });
    }

    /**
     * Binds the data-provider and data-persister registries, each assembled from the
     * explicit {@see JsonApiManager} registrations plus the discovered SPI classes,
     * sorted by descending priority (highest first, ties keeping registration order).
     */
    private function registerRegistries(): void
    {
        $this->app->singleton(DataProviderRegistry::class, function (Container $app): DataProviderRegistry {
            /** @var JsonApiManager $manager */
            $manager = $app->make(JsonApiManager::class);
            /** @var Discovery $discovery */
            $discovery = $app->make(Discovery::class);

            $registrations = $manager->providerRegistrations();
            foreach ($discovery->providers() as $class) {
                $registrations[] = ['provider' => $class, 'priority' => 0];
            }

            \usort($registrations, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);

            $providers = [];
            foreach ($registrations as $registration) {
                $candidate = $registration['provider'];
                $provider = $candidate instanceof DataProviderInterface ? $candidate : $app->make($candidate);
                if (!$provider instanceof DataProviderInterface) {
                    throw new \LogicException(\sprintf(
                        'A registered JSON:API data provider must implement %s; got %s.',
                        DataProviderInterface::class,
                        \is_string($candidate) ? $candidate : \get_debug_type($candidate),
                    ));
                }
                $providers[] = $provider;
            }

            return new DataProviderRegistry($providers);
        });

        $this->app->singleton(DataPersisterRegistry::class, function (Container $app): DataPersisterRegistry {
            /** @var JsonApiManager $manager */
            $manager = $app->make(JsonApiManager::class);
            /** @var Discovery $discovery */
            $discovery = $app->make(Discovery::class);

            $registrations = $manager->persisterRegistrations();
            foreach ($discovery->persisters() as $class) {
                $registrations[] = ['persister' => $class, 'priority' => 0];
            }

            \usort($registrations, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);

            $persisters = [];
            foreach ($registrations as $registration) {
                $candidate = $registration['persister'];
                $persister = $candidate instanceof DataPersisterInterface ? $candidate : $app->make($candidate);
                if (!$persister instanceof DataPersisterInterface) {
                    throw new \LogicException(\sprintf(
                        'A registered JSON:API data persister must implement %s; got %s.',
                        DataPersisterInterface::class,
                        \is_string($candidate) ? $candidate : \get_debug_type($candidate),
                    ));
                }
                $persisters[] = $persister;
            }

            return new DataPersisterRegistry($persisters);
        });
    }

    /**
     * Binds the always-on validation bridge (PLAN decision 6): the pointer builder, the
     * {@see ConstraintTranslator} (its class-keyed extension point assembled from the
     * explicit {@see JsonApiManager} registrations, the discovered translators, and the
     * tagged container bindings), and the {@see ResourceValidator} / {@see FilterValueValidator}
     * that give a resource's constraint metadata teeth as `422`/`400` respectively.
     */
    private function registerValidation(): void
    {
        $this->app->singleton(JsonPointerBuilder::class);

        $this->app->singleton(ConstraintTranslator::class, function (Container $app): ConstraintTranslator {
            return new ConstraintTranslator($this->constraintTranslators($app));
        });

        $this->app->singleton(ResourceValidator::class);
        $this->app->singleton(FilterValueValidator::class);
    }

    /**
     * The registered constraint translators, in resolution order: the explicit
     * {@see JsonApiManager} registrations, then the discovered translator classes, then
     * the tagged container bindings — each resolved to an instance and instanceof-guarded.
     *
     * @return list<ConstraintTranslatorInterface>
     */
    private function constraintTranslators(Container $app): array
    {
        /** @var JsonApiManager $manager */
        $manager = $app->make(JsonApiManager::class);
        /** @var Discovery $discovery */
        $discovery = $app->make(Discovery::class);

        /** @var list<ConstraintTranslatorInterface|class-string<ConstraintTranslatorInterface>> $candidates */
        $candidates = $manager->constraintTranslatorRegistrations();
        foreach ($discovery->translators() as $class) {
            $candidates[] = $class;
        }
        /** @var iterable<ConstraintTranslatorInterface> $tagged */
        $tagged = $app->tagged(self::CONSTRAINT_TRANSLATOR_TAG);
        foreach ($tagged as $translator) {
            $candidates[] = $translator;
        }

        $translators = [];
        foreach ($candidates as $candidate) {
            $translator = $candidate instanceof ConstraintTranslatorInterface ? $candidate : $app->make($candidate);
            if (!$translator instanceof ConstraintTranslatorInterface) {
                throw new \LogicException(\sprintf(
                    'A registered JSON:API constraint translator must implement %s; got %s.',
                    ConstraintTranslatorInterface::class,
                    \is_string($candidate) ? $candidate : \get_debug_type($candidate),
                ));
            }
            $translators[] = $translator;
        }

        return $translators;
    }

    /**
     * Binds the policy-first {@see Authorizer} (PLAN decision 7): the per-type
     * authorization overrides (dedicated `policy:` class + ability renames/disables) are
     * projected off the discovered {@see \haddowg\JsonApiLaravel\Discovery\ResourceDescriptor}s
     * once, at first resolution (after discovery + application service providers have
     * run), and paired with the application's {@see Gate}. Types with no override still
     * flow through the Gate path (honouring any `Gate::policy()`/`Gate::define()` the
     * application registered), inert when neither exists.
     */
    private function registerAuthorization(): void
    {
        $this->app->singleton(Authorizer::class, static function (Container $app): Authorizer {
            /** @var Discovery $discovery */
            $discovery = $app->make(Discovery::class);

            $config = [];
            foreach ($discovery->resources() as $descriptor) {
                // The resource class is carried as the class-level subject token so a
                // declared policy can enforce viewAny/create even for a read-only type that
                // mints no list instance (see Authorizer::authorizeViaPolicy).
                $config[$descriptor->type] = new ResourceAuthorization(
                    $descriptor->policy,
                    $descriptor->abilities,
                    $descriptor->class,
                );
            }

            /** @var \Illuminate\Contracts\Auth\Access\Gate $gate */
            $gate = $app->make(\Illuminate\Contracts\Auth\Access\Gate::class);

            return new Authorizer($gate, $config);
        });
    }

    /**
     * Binds the relationship-read machinery (PLAN decisions 8): the thin
     * {@see TypeMetadataResolver} (relation metadata off the Server), the provider-agnostic
     * {@see RelationCriteriaFactory}, the {@see RelatedIncludeBatcher} (the `?include`
     * eager-load orchestrator) and {@see RelationCountBatcher} (the `?withCount` count
     * orchestrator), and the request-scoped {@see RequestScopedRelationshipCount} count-seam
     * holder threaded into every Server and swapped per read by the handler.
     */
    private function registerRelationships(): void
    {
        $this->app->singleton(TypeMetadataResolver::class);
        $this->app->singleton(RelationCriteriaFactory::class);

        // The count-seam holder is a stable singleton (injected into the memoized Server
        // once); the handler swaps its per-request backing in. For a long-lived worker
        // (Octane/queue) rebind it `scoped()` — per-request FPM/CLI is unaffected.
        $this->app->singleton(RequestScopedRelationshipCount::class);

        $this->app->singleton(RelatedIncludeBatcher::class, static function (Container $app): RelatedIncludeBatcher {
            return new RelatedIncludeBatcher(
                $app->make(DataProviderRegistry::class),
                $app->make(TypeMetadataResolver::class),
            );
        });

        $this->app->singleton(RelationCountBatcher::class, static function (Container $app): RelationCountBatcher {
            return new RelationCountBatcher(
                $app->make(DataProviderRegistry::class),
                $app->make(TypeMetadataResolver::class),
                $app->make(RelationCriteriaFactory::class),
            );
        });
    }

    /**
     * Binds the {@see ServerRegistry}: one {@see ServerFactory} per configured server,
     * each holding that server's resource class-strings and building its immutable core
     * {@see \haddowg\JsonApi\Server\Server} lazily on first dispatch.
     */
    private function registerServers(): void
    {
        $this->app->singleton(ServerRegistry::class, function (Container $app): ServerRegistry {
            /** @var Discovery $discovery */
            $discovery = $app->make(Discovery::class);
            $psr17 = $app->make(Psr17Factory::class);
            $handler = $app->make(CrudOperationHandler::class);

            $resolver = static function (string $class) use ($app): object {
                $instance = $app->make($class);
                \assert(\is_object($instance));

                return $instance;
            };

            /** @var string $baseUri */
            $baseUri = config('jsonapi.base_uri', '');
            /** @var string $version */
            $version = config('jsonapi.version', '1.1');
            /** @var int $maxPerPage */
            $maxPerPage = config('jsonapi.pagination.max_per_page', 100);
            /** @var int $maxIncludeDepth */
            $maxIncludeDepth = config('jsonapi.max_include_depth', 0);
            /** @var bool $strict */
            $strict = config('jsonapi.strict_query_parameters', true);

            /** @var array<string, mixed> $serversConfig */
            $serversConfig = config('jsonapi.servers', []);

            // The stable count-seam holder is shared across every server and the handler.
            $relationshipCount = $app->make(RequestScopedRelationshipCount::class);

            // The storage-aware load-state predicate is optional: the Eloquent reference
            // binds one (so a lazy relation renders links-only), the in-memory witness leaves
            // it unbound (every relation treated as loaded — the standalone default).
            $loadState = $app->bound(RelationshipLoadStateInterface::class)
                ? $app->make(RelationshipLoadStateInterface::class)
                : null;
            \assert($loadState === null || $loadState instanceof RelationshipLoadStateInterface);

            $factories = [];
            foreach (\array_keys($serversConfig) as $server) {
                $server = (string) $server;
                $resourceClasses = \array_map(
                    static fn($descriptor): string => $descriptor->class,
                    $discovery->resourcesFor($server),
                );

                $factories[$server] = new ServerFactory(
                    $psr17,
                    $psr17,
                    $handler,
                    $resolver,
                    $resourceClasses,
                    $baseUri,
                    $version,
                    $maxPerPage,
                    $maxIncludeDepth,
                    $strict,
                    $relationshipCount,
                    $loadState,
                );
            }

            return new ServerRegistry($factories);
        });
    }

    private function registerExceptionRenderer(): void
    {
        $this->app->singleton(JsonApiExceptionRenderer::class, function (Container $app): JsonApiExceptionRenderer {
            /** @var bool $debug */
            $debug = config('app.debug', false);

            return new JsonApiExceptionRenderer(
                $app->make(ServerRegistry::class),
                $app->make(PsrHttpFactory::class),
                $app->make(HttpFoundationFactory::class),
                $debug,
                $this->exceptionMappers($app),
            );
        });
    }

    /**
     * The tagged application exception mappers, resolved in registration order.
     *
     * @return iterable<ExceptionMapperInterface>
     */
    private function exceptionMappers(Container $app): iterable
    {
        /** @var iterable<ExceptionMapperInterface> $mappers */
        $mappers = $app->tagged('jsonapi.exception_mapper');

        return $mappers;
    }

    private function registerRouteMacro(): void
    {
        $app = $this->app;

        Router::macro('jsonApi', function (?string $server = null) use ($app): void {
            /** @var Router $this */
            /** @var RouteRegistrar $registrar */
            $registrar = $app->make(RouteRegistrar::class);
            $registrar->registerServer($this, $server ?? ServerRegistry::DEFAULT_SERVER);
        });
    }

    /**
     * Hooks the route-scoped {@see JsonApiExceptionRenderer} onto Laravel's exception
     * handler, so a throwable on a JSON:API route renders as a spec-compliant JSON:API
     * error document while non-JSON:API routes are left to the default handler.
     */
    private function registerExceptionRenderable(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        if (!\method_exists($handler, 'renderable')) {
            return;
        }

        $app = $this->app;

        $handler->renderable(static function (\Throwable $throwable, Request $request) use ($app): mixed {
            /** @var JsonApiExceptionRenderer $renderer */
            $renderer = $app->make(JsonApiExceptionRenderer::class);
            if (!$renderer->handles($request)) {
                return null;
            }

            return $renderer->render($throwable, $request);
        });
    }
}
