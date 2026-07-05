<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Eloquent;

use haddowg\JsonApiLaravel\Discovery\ResourceDescriptor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Resolves the `type → Eloquent model` map the auto-registered reference
 * {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider} /
 * {@see \haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister} pair is
 * built from (ADR 0019) — the Laravel twin of the Symfony bundle's
 * `DoctrineEntityMapPass`, run lazily off the discovered {@see ResourceDescriptor}s
 * instead of at container compile time.
 *
 * Two of the three resolution tiers live here, first match wins per type:
 *
 *  1. **(not here)** an explicit `JsonApi::provider()`/`persister()` registration —
 *     any hand-wired provider sits above the auto pair in the registry's priority
 *     order, so it shadows this map entirely for the types it `supports()`;
 *  2. the **declared model** — `#[AsJsonApiResource(model: Album::class)]`, carried on
 *     the descriptor (scan-time validated, snapshot-safe). Two resources may declare
 *     the SAME model for different types ("two types, one model"), but one type
 *     declaring two different models is a wiring fault and throws;
 *  3. the **convention guess** — the kebab/plural type studly-singularized under the
 *     configured namespace (`albums` → `App\Models\Album`,
 *     `public-profiles` → `App\Models\PublicProfile`), claimed only when the class
 *     exists and extends Eloquent's {@see Model}. No match, no claim: a type resolving
 *     through neither tier stays out of the map, so the auto pair never `supports()`
 *     it and the no-provider wiring error is exactly what it was before this resolver
 *     existed. A null/empty namespace disables the convention tier.
 *
 * The resolved map is memoized: both registry closures (provider + persister) read the
 * SAME map, so the reference pair can never disagree about a type's model.
 */
final class ModelMapResolver
{
    /**
     * @var array<string, class-string<Model>>|null
     */
    private ?array $map = null;

    /**
     * @param list<ResourceDescriptor> $descriptors    the discovered resource descriptors
     * @param string|null              $modelNamespace the namespace the convention tier guesses under (`jsonapi.eloquent.model_namespace`); null/empty disables convention
     */
    public function __construct(
        private readonly array $descriptors,
        private readonly ?string $modelNamespace,
    ) {}

    /**
     * The resolved `type → Eloquent model FQCN` map.
     *
     * @return array<string, class-string<Model>>
     */
    public function map(): array
    {
        return $this->map ??= $this->resolve();
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private function resolve(): array
    {
        $map = [];

        // Tier 2: the declared model. Resolved for every descriptor first, so a type's
        // declaration always beats another resource's convention guess for the same type.
        foreach ($this->descriptors as $descriptor) {
            if ($descriptor->model === null) {
                continue;
            }

            if (isset($map[$descriptor->type]) && $map[$descriptor->type] !== $descriptor->model) {
                throw new \LogicException(\sprintf(
                    'JSON:API type "%s" is mapped to two different Eloquent models: "%s" and "%s".',
                    $descriptor->type,
                    $map[$descriptor->type],
                    $descriptor->model,
                ));
            }

            $map[$descriptor->type] = $descriptor->model;
        }

        // Tier 3: the convention guess for every type still unmapped.
        foreach ($this->descriptors as $descriptor) {
            if (isset($map[$descriptor->type])) {
                continue;
            }

            $guess = $this->guess($descriptor->type);
            if ($guess !== null) {
                $map[$descriptor->type] = $guess;
            }
        }

        return $map;
    }

    /**
     * The convention-tier guess for a type: the kebab/plural type studly-singularized
     * under the configured namespace, claimed only when that class exists and is an
     * Eloquent model.
     *
     * @return class-string<Model>|null
     */
    private function guess(string $type): ?string
    {
        if ($this->modelNamespace === null || $this->modelNamespace === '') {
            return null;
        }

        $class = \rtrim($this->modelNamespace, '\\') . '\\' . Str::studly(Str::singular($type));

        if (!\class_exists($class) || !\is_a($class, Model::class, true)) {
            return null;
        }

        return $class;
    }
}
