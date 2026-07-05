<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApi\Serializer\ResourceLinkContributorInterface;
use haddowg\JsonApi\Validation\SchemaValueValidator;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInvoker;
use haddowg\JsonApiLaravel\Action\ActionLinkContributor;
use haddowg\JsonApiLaravel\Action\ActionRegistry;
use haddowg\JsonApiLaravel\Authorization\Authorizer;
use haddowg\JsonApiLaravel\Authorization\ResourceAuthorization;
use haddowg\JsonApiLaravel\Console\ClearCommand;
use haddowg\JsonApiLaravel\Console\JsonSchemaExportCommand;
use haddowg\JsonApiLaravel\Console\OpenApiExportCommand;
use haddowg\JsonApiLaravel\Console\OptimizeCommand;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataPersister\WriteTransactionContext;
use haddowg\JsonApiLaravel\DataProvider\DataProviderInterface;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\DataProvider\RelatedIncludeBatcher;
use haddowg\JsonApiLaravel\DataProvider\RelationCountBatcher;
use haddowg\JsonApiLaravel\DataProvider\RelationCriteriaFactory;
use haddowg\JsonApiLaravel\DataProvider\RelationshipWindowBatcher;
use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\EventListener\ResourceHookSubscriber;
use haddowg\JsonApiLaravel\Exception\ExceptionMapperInterface;
use haddowg\JsonApiLaravel\Exception\JsonApiExceptionRenderer;
use haddowg\JsonApiLaravel\Http\DescribedbyStamper;
use haddowg\JsonApiLaravel\Http\ResponseHeadersRegistry;
use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\OpenApi\Config\OpenApiConfig;
use haddowg\JsonApiLaravel\OpenApi\Config\OpenApiConfigResolver;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\OpenApi\DocumentWarmer;
use haddowg\JsonApiLaravel\OpenApi\JsonSchemaFactory;
use haddowg\JsonApiLaravel\OpenApi\Metadata\ActionMetadataProviderInterface;
use haddowg\JsonApiLaravel\OpenApi\Metadata\IncludePathResolver;
use haddowg\JsonApiLaravel\OpenApi\Metadata\MetadataSource;
use haddowg\JsonApiLaravel\OpenApi\Metadata\PaginatorKindResolver;
use haddowg\JsonApiLaravel\OpenApi\Metadata\TagNameResolver;
use haddowg\JsonApiLaravel\Operation\CrudOperationHandler;
use haddowg\JsonApiLaravel\Operation\TargetResolver;
use haddowg\JsonApiLaravel\Routing\OpenApiRouteRegistrar;
use haddowg\JsonApiLaravel\Routing\RouteRegistrar;
use haddowg\JsonApiLaravel\Serializer\RequestScopedRelationshipCount;
use haddowg\JsonApiLaravel\Serializer\RequestScopedRelationshipLinkage;
use haddowg\JsonApiLaravel\Serializer\RequestScopedRelationshipPagination;
use haddowg\JsonApiLaravel\Server\IdEncoderResolver;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
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
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
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

    /**
     * The container tag an {@see \haddowg\JsonApiLaravel\OpenApi\OpenApiFactoryInterface}
     * binding carries to join the OpenAPI document decorator chain. Decorators are applied in
     * **registration order**, so a later-registered decorator refines an earlier one and gets
     * the final word (Laravel's `tagged()` carries no priority — later binding wins). Bind +
     * tag a decorator to mutate the projected document wholesale.
     */
    public const string OPENAPI_FACTORY_TAG = 'jsonapi.openapi_factory';

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
        $this->registerActions();
        $this->registerAtomic();
        $this->registerResponseHeaders();
        $this->registerServers();
        $this->registerOpenApi();

        $this->app->singleton(TargetResolver::class);
        $this->app->singleton(CrudOperationHandler::class);

        $this->registerExceptionRenderer();

        $this->app->singleton(RouteRegistrar::class, static function (Container $app): RouteRegistrar {
            /** @var array<string, array{prefix?: string, middleware?: list<string>|string, domain?: string|null}> $servers */
            $servers = config('jsonapi.servers', []);
            /** @var bool $atomicEnabled */
            $atomicEnabled = config('jsonapi.atomic_operations.enabled', false);
            /** @var string $atomicPath */
            $atomicPath = config('jsonapi.atomic_operations.path', '/operations');

            // Resolves a resource class to its Id field's declared route pattern (PLAN
            // decision 4): the resource is constructed via the container (the same lazy
            // resolver core uses) purely to read `fields()` — never the full Server, so route
            // registration stays cheap. Resilient: any construction failure degrades to the
            // single-segment default rather than breaking routing.
            $idPatternResolver = static function (string $class) use ($app): ?string {
                try {
                    $resource = $app->make($class);
                    if (!$resource instanceof \haddowg\JsonApi\Resource\AbstractResource) {
                        return null;
                    }
                    foreach ($resource->fields() as $field) {
                        if ($field instanceof \haddowg\JsonApi\Resource\Field\Id) {
                            return $field->routePattern();
                        }
                    }

                    return null;
                } catch (\Throwable) {
                    return null;
                }
            };

            return new RouteRegistrar($app->make(Discovery::class), $servers, $atomicEnabled, $atomicPath, $idPatternResolver);
        });
    }

    /**
     * Binds the custom-actions subsystem (PLAN decision 12): the {@see ActionRegistry}
     * (descriptors from the cacheable discovery snapshot + a container handler-resolver +
     * the tag resolver), aliased to the {@see ActionMetadataProviderInterface} the OpenAPI
     * {@see MetadataSource} reads a type's actions through, and the {@see ActionInvoker} the
     * {@see CrudOperationHandler}'s {@see \haddowg\JsonApi\Operation\CustomActionOperation}
     * arm delegates to.
     */
    private function registerActions(): void
    {
        $this->app->singleton(ActionRegistry::class, static function (Container $app): ActionRegistry {
            /** @var Discovery $discovery */
            $discovery = $app->make(Discovery::class);

            $resolver = static function (string $class) use ($app): ActionHandlerInterface {
                $handler = $app->make($class);
                \assert($handler instanceof ActionHandlerInterface);

                return $handler;
            };

            // The explicit OpenAPI tags each mount type declares, so an action with no tags of
            // its own inherits the mount type's tags before the humanized default (bundle parity).
            $typeTags = [];
            foreach ($discovery->resources() as $descriptor) {
                if ($descriptor->tags !== []) {
                    $typeTags[$descriptor->type] = $descriptor->tags;
                }
            }

            return new ActionRegistry($discovery->actions(), $resolver, $app->make(TagNameResolver::class), $typeTags);
        });

        // The OpenAPI metadata source reads a type's actions through this interface; the
        // registry IS the provider (the "A stubs actions() first, B fills it" handoff).
        $this->app->alias(ActionRegistry::class, ActionMetadataProviderInterface::class);

        $this->app->singleton(ActionInvoker::class);
    }

    /**
     * Binds the Atomic Operations collaborators (PLAN decision 12).
     *
     * The deferred-hook {@see WriteTransactionContext} is a **singleton**: its only consumer,
     * the singleton {@see CrudOperationHandler}, captures it at first construction, so a
     * `scoped()` binding would silently mint per-request instances the handler never uses
     * (and leave any other resolver observing a different instance than the handler's). A
     * singleton makes the binding honest — one instance everywhere — and cross-batch
     * cleanliness rests on the executor's always-run {@see WriteTransactionContext::deactivate()}
     * (in a `finally` on both the commit and rollback paths); {@see WriteTransactionContext::reset()}
     * is available for an Octane/queue worker reset hook.
     *
     * The cross-store {@see InMemorySnapshotCoordinator} stays `scoped()` (the reference
     * in-memory witness's per-request snapshot holder). The
     * {@see \haddowg\JsonApiLaravel\Atomic\AtomicLoopBackend} is built per-batch by the
     * handler, so nothing else is bound here.
     */
    private function registerAtomic(): void
    {
        $this->app->singleton(WriteTransactionContext::class);
        $this->app->scoped(InMemorySnapshotCoordinator::class);
    }

    /**
     * Binds the response-header registry (PLAN decision 12): the per-type declarative
     * cache + deprecation/sunset config projected off the discovered
     * {@see \haddowg\JsonApiLaravel\Discovery\ResourceDescriptor}s (a discovery-time
     * projection, like the Authorizer config), layered over the global
     * `jsonapi.defaults.*` defaults. The {@see \haddowg\JsonApiLaravel\Http\ResponseHeadersMiddleware}
     * queries it per request.
     */
    private function registerResponseHeaders(): void
    {
        $this->app->singleton(ResponseHeadersRegistry::class, static function (Container $app): ResponseHeadersRegistry {
            /** @var Discovery $discovery */
            $discovery = $app->make(Discovery::class);

            $byType = [];
            foreach ($discovery->resources() as $descriptor) {
                if ($descriptor->headers !== []) {
                    $byType[$descriptor->type] = $descriptor->headers;
                }
            }

            /** @var array<string, mixed> $defaultCache */
            $defaultCache = config('jsonapi.defaults.cache_headers', []);

            $defaultDeprecation = [
                'deprecation' => config('jsonapi.defaults.deprecation'),
                'sunset' => config('jsonapi.defaults.sunset'),
                'sunset_link' => config('jsonapi.defaults.sunset_link'),
            ];

            return new ResponseHeadersRegistry($byType, $defaultCache, $defaultDeprecation);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/jsonapi.php' => $this->app->configPath('jsonapi.php'),
        ], 'jsonapi-config');

        $this->registerRouteMacro();
        $this->registerEventSubscribers();

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
            $this->registerOpenApiRoutes($router);
        }

        $this->registerExceptionRenderable();
        $this->registerConsole();
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

        // The core opis value-validator gives a Shape constraint teeth. Registered only
        // when opis/json-schema is installed — without it a Shape still documents its
        // OpenAPI shape but is not value-validated (the same optional posture the
        // testing kit's schema assertions have). Independent of any structural-linter
        // toggle: a declared Shape exists solely to be enforced (ADR 0013).
        $opisInstalled = \class_exists(\Opis\JsonSchema\Validator::class);
        if ($opisInstalled) {
            $this->app->singleton(SchemaValueValidator::class);
        }

        $this->app->singleton(ResourceValidator::class, function (Container $app) use ($opisInstalled): ResourceValidator {
            return new ResourceValidator(
                $app->make(\Illuminate\Contracts\Validation\Factory::class),
                $app->make(ConstraintTranslator::class),
                $app->make(JsonPointerBuilder::class),
                $opisInstalled ? $app->make(SchemaValueValidator::class) : null,
            );
        });
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

        // The count/pagination/linkage seam holders are stable singletons (injected into the
        // memoized Server once); the handler clears all three at the start of every dispatch
        // and swaps each read's per-request backing in, so a long-lived worker (Octane/queue)
        // never inherits a prior request's backing. A scoped() rebind would be ineffective:
        // the memoized Server and the singleton handler capture the instances at construction.
        $this->app->singleton(RequestScopedRelationshipCount::class);
        $this->app->singleton(RequestScopedRelationshipPagination::class);
        $this->app->singleton(RequestScopedRelationshipLinkage::class);

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

        // The Relationship Queries profile orchestrator: it windows each rendered to-many
        // relation to page 1 of its relatedQuery-ordered/filtered set through the provider's
        // batched fetch (the Eloquent groupLimit/ROW_NUMBER push-down) and supplies the linkage +
        // pagination out-of-band via the two holders above.
        $this->app->singleton(RelationshipWindowBatcher::class, static function (Container $app): RelationshipWindowBatcher {
            return new RelationshipWindowBatcher(
                $app->make(DataProviderRegistry::class),
                $app->make(TypeMetadataResolver::class),
                $app->make(RelationCriteriaFactory::class),
                $app->make(FilterValueValidator::class),
            );
        });
    }

    /**
     * Binds the {@see ServerRegistry}: one {@see ServerFactory} per configured server,
     * each holding that server's resource class-strings and building its immutable core
     * {@see \haddowg\JsonApi\Server\Server} lazily on first dispatch. Also binds the
     * {@see IdEncoderResolver} the reference Eloquent provider/persister decode wire
     * ids through (ADR 0014) — resolving a type's resource from the SAME memoized
     * discovery descriptors, constructed via the container on first use.
     */
    private function registerServers(): void
    {
        $this->app->singleton(IdEncoderResolver::class, static function (Container $app): IdEncoderResolver {
            return new IdEncoderResolver(
                $app->make(Discovery::class),
                static function (string $class) use ($app): object {
                    $instance = $app->make($class);
                    \assert(\is_object($instance));

                    return $instance;
                },
            );
        });

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

            // Additional server-registered profiles (`jsonapi.profiles`, class-strings):
            // e.g. core's CursorPaginationProfile so a cursor page advertises the
            // published cursor-pagination profile. Instantiated once, shared by every
            // server (profiles are stateless VOs).
            /** @var list<class-string<\haddowg\JsonApi\Schema\Profile\ProfileInterface>> $profileClasses */
            $profileClasses = config('jsonapi.profiles', []);
            $profiles = [];
            foreach ($profileClasses as $profileClass) {
                $profile = $app->make($profileClass);
                \assert($profile instanceof \haddowg\JsonApi\Schema\Profile\ProfileInterface);
                $profiles[] = $profile;
            }

            /** @var array<string, mixed> $serversConfig */
            $serversConfig = config('jsonapi.servers', []);

            // The stable seam holders are shared across every server and the handler.
            $relationshipCount = $app->make(RequestScopedRelationshipCount::class);
            $relationshipPagination = $app->make(RequestScopedRelationshipPagination::class);
            $relationshipLinkage = $app->make(RequestScopedRelationshipLinkage::class);

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
                $resourceClasses = [];
                // The per-resource serializer/hydrator overrides (ADR 0015), keyed by
                // resource class — threaded into core's register() by the factory so the
                // overridden concern wins while the other stays field-driven.
                $serializerOverrides = [];
                $hydratorOverrides = [];
                foreach ($discovery->resourcesFor($server) as $descriptor) {
                    $resourceClasses[] = $descriptor->class;
                    if ($descriptor->serializer !== null) {
                        $serializerOverrides[$descriptor->class] = $descriptor->serializer;
                    }
                    if ($descriptor->hydrator !== null) {
                        $hydratorOverrides[$descriptor->class] = $descriptor->hydrator;
                    }
                }

                // The standalone serializers + hydrators this server exposes, keyed by
                // JSON:API type (PLAN decision 3, bundle ADR 0024) — a resource-less type
                // served through core's registerSerializerHydrator() pair (the factory
                // registers a type's two halves together, since core rejects a second
                // registration for the same type).
                $standaloneSerializers = [];
                foreach ($discovery->serializersFor($server) as $descriptor) {
                    $standaloneSerializers[$descriptor->type] = $descriptor->class;
                }
                $standaloneHydrators = [];
                foreach ($discovery->hydratorsFor($server) as $descriptor) {
                    $standaloneHydrators[$descriptor->type] = $descriptor->class;
                }

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
                    $relationshipPagination,
                    $relationshipLinkage,
                    $this->actionLinkContributor($app, $server),
                    $app->make(\Illuminate\Contracts\Events\Dispatcher::class),
                    $server,
                    $standaloneSerializers,
                    $standaloneHydrators,
                    $serializerOverrides,
                    $hydratorOverrides,
                    $profiles,
                );
            }

            return new ServerRegistry($factories);
        });
    }

    /**
     * Builds the per-server {@see ActionLinkContributor} threaded into a server's
     * {@see \haddowg\JsonApi\Server\Server} — the out-of-band merge of each `asLink` custom
     * action's URL onto its mount type's resources. Returns `null` when the server declares no
     * `asLink` action, so a server with none pays nothing (its resource links are exactly the
     * author's + the convention self link). The serializer is resolved lazily (via the
     * memoized {@see ServerRegistry}) so building the Server — which is threaded this
     * contributor — does not recurse.
     */
    private function actionLinkContributor(Container $app, string $server): ?ResourceLinkContributorInterface
    {
        /** @var ActionRegistry $registry */
        $registry = $app->make(ActionRegistry::class);

        $byType = [];
        foreach ($registry->forServer($server) as $descriptor) {
            if ($descriptor->asLink) {
                $byType[$descriptor->type][] = $descriptor;
            }
        }

        if ($byType === []) {
            return null;
        }

        $resolver = static fn(string $type): \haddowg\JsonApi\Serializer\SerializerInterface => $app->make(ServerRegistry::class)->get($server)->serializerFor($type);

        return new ActionLinkContributor(
            $byType,
            $resolver,
            $app->make(UrlGenerator::class),
            $app->make(Authorizer::class),
        );
    }

    /**
     * Binds the OpenAPI subtree (PLAN decision 11): the resolved {@see OpenApiConfig},
     * the metadata resolvers + {@see MetadataSource} (core's metadata contract, read from
     * discovery), the {@see DocumentFactory} (+ tagged {@see OpenApiFactoryInterface}
     * decorators) and {@see JsonSchemaFactory}, the {@see ArtifactStore}, and the two
     * warmers the optimize pipeline runs.
     */
    private function registerOpenApi(): void
    {
        $this->app->singleton(OpenApiConfig::class, function (Container $app): OpenApiConfig {
            /** @var array<string, mixed> $openapi */
            $openapi = config('jsonapi.openapi', []);

            return (new OpenApiConfigResolver())->resolve($openapi, $this->serverNames());
        });

        $this->app->singleton(TagNameResolver::class);
        $this->app->singleton(PaginatorKindResolver::class);
        $this->app->singleton(IncludePathResolver::class);

        $this->app->singleton(MetadataSource::class, function (Container $app): MetadataSource {
            /** @var OpenApiConfig $config */
            $config = $app->make(OpenApiConfig::class);
            /** @var bool $atomicEnabled */
            $atomicEnabled = config('jsonapi.atomic_operations.enabled', false);
            /** @var string $atomicPath */
            $atomicPath = config('jsonapi.atomic_operations.path', '/operations');

            $actions = $app->bound(ActionMetadataProviderInterface::class)
                ? $app->make(ActionMetadataProviderInterface::class)
                : null;
            \assert($actions === null || $actions instanceof ActionMetadataProviderInterface);

            return new MetadataSource(
                $app->make(ServerRegistry::class),
                $app->make(Discovery::class),
                $app->make(TypeMetadataResolver::class),
                $app->make(PaginatorKindResolver::class),
                $app->make(TagNameResolver::class),
                $app->make(IncludePathResolver::class),
                $this->serverNames(),
                $config->serverDocuments,
                $atomicEnabled,
                $atomicPath,
                $actions,
            );
        });

        $this->app->singleton(DescribedbyStamper::class, static function (Container $app): DescribedbyStamper {
            /** @var OpenApiConfig $config */
            $config = $app->make(OpenApiConfig::class);
            /** @var UrlGenerator $url */
            $url = $app->make(UrlGenerator::class);

            return new DescribedbyStamper($url, $config->describedby, $config->combined);
        });

        $this->app->singleton(ArtifactStore::class, static function (Container $app): ArtifactStore {
            /** @var string|null $path */
            $path = config('jsonapi.openapi.cache_path');
            $dir = \is_string($path) && $path !== '' ? $path : storage_path('framework/cache/jsonapi-openapi');

            return new ArtifactStore($dir);
        });

        $this->app->singleton(DocumentFactory::class, function (Container $app): DocumentFactory {
            /** @var OpenApiConfig $config */
            $config = $app->make(OpenApiConfig::class);
            /** @var iterable<\haddowg\JsonApiLaravel\OpenApi\OpenApiFactoryInterface> $decorators */
            $decorators = $app->tagged(self::OPENAPI_FACTORY_TAG);

            return new DocumentFactory(
                $app->make(MetadataSource::class),
                $config->enumDescriptionMode,
                $decorators,
            );
        });

        $this->app->singleton(JsonSchemaFactory::class, static function (Container $app): JsonSchemaFactory {
            /** @var OpenApiConfig $config */
            $config = $app->make(OpenApiConfig::class);

            return new JsonSchemaFactory(
                $app->make(ServerRegistry::class),
                $app->make(TypeMetadataResolver::class),
                $app->make(Discovery::class),
                $config->enumDescriptionMode,
            );
        });

        $this->app->singleton(DocumentWarmer::class, function (Container $app): DocumentWarmer {
            /** @var OpenApiConfig $config */
            $config = $app->make(OpenApiConfig::class);

            $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;
            \assert($logger === null || $logger instanceof LoggerInterface);

            return new DocumentWarmer(
                $app->make(DocumentFactory::class),
                $app->make(JsonSchemaFactory::class),
                $app->make(ArtifactStore::class),
                $this->serverNames(),
                $config->enabled,
                $config->combined,
                $config->publicPath,
                $logger,
            );
        });

        $this->app->singleton(ServableResourceWarmer::class, function (Container $app): ServableResourceWarmer {
            return new ServableResourceWarmer(
                $app->make(ServerRegistry::class),
                $app->make(Discovery::class),
                $app->make(DataProviderRegistry::class),
                $app->make(DataPersisterRegistry::class),
                $app->make(TypeMetadataResolver::class),
                $this->serverNames(),
            );
        });
    }

    /**
     * Registers the OpenAPI documentation routes (gated by the expose rule) through the
     * {@see OpenApiRouteRegistrar}, route:cache-safe like the CRUD routes.
     */
    private function registerOpenApiRoutes(Router $router): void
    {
        /** @var OpenApiConfig $config */
        $config = $this->app->make(OpenApiConfig::class);
        /** @var bool $debug */
        $debug = config('app.debug', false);

        (new OpenApiRouteRegistrar($config, $this->serverNames(), $debug))->register($router);
    }

    /**
     * Registers the artisan commands (exports + the optimize/clear pair) and wires the
     * optimize pipeline so `php artisan optimize` warms the JSON:API artifacts + validates
     * servability, and `optimize:clear` clears them.
     */
    private function registerConsole(): void
    {
        $this->commands([
            OpenApiExportCommand::class,
            JsonSchemaExportCommand::class,
            OptimizeCommand::class,
            ClearCommand::class,
        ]);

        $this->optimizes(
            optimize: 'jsonapi:optimize',
            clear: 'jsonapi:clear',
            key: 'jsonapi',
        );
    }

    /**
     * The declared server names (`jsonapi.servers` keys), `default` first — the per-server
     * type source the OpenAPI metadata + warmers iterate.
     *
     * @return list<string>
     */
    private function serverNames(): array
    {
        /** @var array<string, mixed> $servers */
        $servers = config('jsonapi.servers', []);
        $names = \array_map(static fn($name): string => (string) $name, \array_keys($servers));

        $ordered = \in_array(ServerRegistry::DEFAULT_SERVER, $names, true) ? [ServerRegistry::DEFAULT_SERVER] : [];
        foreach ($names as $name) {
            if ($name !== ServerRegistry::DEFAULT_SERVER) {
                $ordered[] = $name;
            }
        }

        return $ordered;
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

    /**
     * Registers the resource lifecycle-hook subscriber (PLAN decision 10) as lazy
     * class-string listeners on each hook-relevant event, so an
     * {@see ResourceLifecycleHooksInterface} resource's methods run off the dispatched
     * events. Registered by class-string (not `Event::subscribe`, which would
     * construct the subscriber — and its {@see \haddowg\JsonApiLaravel\Server\ServerRegistry}/discovery
     * — at boot); the subscriber resolves from the container only when an event fires.
     * Always registered (independent of route caching), since dispatch is a runtime
     * concern.
     */
    private function registerEventSubscribers(): void
    {
        /** @var \Illuminate\Contracts\Events\Dispatcher $events */
        $events = $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class);

        foreach (ResourceHookSubscriber::eventMap() as $event => $method) {
            $events->listen($event, [ResourceHookSubscriber::class, $method]);
        }
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
