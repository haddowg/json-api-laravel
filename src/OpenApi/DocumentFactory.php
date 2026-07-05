<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

use haddowg\JsonApi\OpenApi\EnumDescriptionMode;
use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApiLaravel\OpenApi\Metadata\MetadataSource;
use haddowg\JsonApiLaravel\Server\ServerRegistry;

/**
 * Builds the OpenAPI 3.1 {@see OpenApi} document for one server (PLAN decision 11) —
 * the package's pure-projection entry point: it composes the {@see MetadataSource}
 * (which reads the live registry into core's metadata contract) with the core
 * {@see OpenApiProjector} (which projects that contract into the document).
 *
 * The projector is configured once with the app's {@see EnumDescriptionMode}
 * (`jsonapi.openapi.enum_value_descriptions`) — the single {@see SchemaProjector}
 * carrying it is shared by the projector and its {@see OperationProjector} so component
 * schemas and operation-body schemas surface backed-enum descriptions identically.
 *
 * Building is never per-request: the {@see DocumentWarmer} pre-builds each server's
 * document at `artisan optimize` and the controller serves the artifact; this factory
 * is the build itself (the warmer's source, and the controller's dev lazy-build
 * fallback). It is **pure** — no I/O — so it is cheap to call in a test and safe to
 * memoize.
 *
 * The decorator seam is applied **here**, after the core projection: every registered
 * {@see OpenApiFactoryInterface} receives the built {@see OpenApi} VO and returns a mutated
 * one, applied in **registration order** — so a later-registered decorator refines an
 * earlier one and gets the final word (Laravel's `tagged()` carries no priority; later
 * binding wins, the framework's own convention). Because every build path flows through this
 * factory, decorating here covers all three uniformly; an app's decorators get the last word
 * over anything the projector produced.
 */
final class DocumentFactory
{
    /**
     * The reserved artifact key the combined document is warmed/served/decorated under,
     * distinct from any per-server key (a JSON:API server name is never a bracketed
     * token).
     */
    public const string COMBINED_KEY = '[combined]';

    private readonly OpenApiProjector $projector;

    /**
     * @var list<OpenApiFactoryInterface>
     */
    private readonly array $decorators;

    /**
     * @param iterable<OpenApiFactoryInterface> $decorators the registered decorators, in registration order; **applied** in that same order, so a later-registered decorator refines an earlier one and gets the final mutation (Laravel's `tagged()` carries no priority — later binding wins, the framework's own override convention)
     */
    public function __construct(
        private readonly MetadataSource $metadata,
        EnumDescriptionMode $enumDescriptionMode = EnumDescriptionMode::Both,
        iterable $decorators = [],
    ) {
        $schemaProjector = new SchemaProjector($enumDescriptionMode);
        $this->projector = new OpenApiProjector(
            $schemaProjector,
            new OperationProjector($schemaProjector),
        );

        $this->decorators = \is_array($decorators) ? \array_values($decorators) : \iterator_to_array($decorators, false);
    }

    /**
     * The OpenAPI document for `$serverName` (the implicit `default` server when null).
     *
     * @throws \LogicException when an unknown server name is requested
     */
    public function forServer(?string $serverName = null): OpenApi
    {
        $serverName ??= ServerRegistry::DEFAULT_SERVER;

        return $this->decorate(
            $this->projector->project($this->metadata->forServer($serverName)),
            $serverName,
        );
    }

    /**
     * The single **combined** OpenAPI document spanning every declared server (PLAN §7)
     * — the `multi_server: combined` document, unioning every server's types, advertised
     * base URIs, tag definitions and security schemes into one document.
     *
     * @throws \LogicException when two servers declare the same JSON:API type
     */
    public function combined(): OpenApi
    {
        return $this->decorate(
            $this->projector->project($this->metadata->combined()),
            self::COMBINED_KEY,
        );
    }

    /**
     * Runs the built document through every registered decorator in registration order (so
     * the last-registered decorator gets the final mutation).
     */
    private function decorate(OpenApi $document, string $server): OpenApi
    {
        foreach ($this->decorators as $decorator) {
            $document = $decorator->decorate($document, $server);
        }

        return $document;
    }
}
