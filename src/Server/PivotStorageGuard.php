<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\PivotAwareProviderInterface;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;

/**
 * Checks that a `belongsToMany` declaring stored pivot fields has a provider that reads its
 * pivot rows. Every linkage member renders its `meta.pivot`, and the provider of the
 * relation's related type reads the stored values, so one that can't would render members
 * without the pivot the OpenAPI document promises.
 *
 * @internal
 */
final class PivotStorageGuard
{
    /**
     * @param \Closure(): DataProviderRegistry $providers
     */
    public function __construct(private readonly \Closure $providers) {}

    /**
     * @throws \LogicException when a relation's stored pivot fields have no provider reading them
     */
    public function check(string $type, AbstractResource $resource): void
    {
        $providers = null;
        foreach ($resource->allFields() as $relation) {
            if (!$relation instanceof BelongsToMany || $relation->storedPivotFields() === []) {
                continue;
            }

            $relatedType = $relation->relatedTypes()[0] ?? null;
            $providers ??= ($this->providers)();
            if ($relatedType === null || !$providers->supportsType($relatedType)) {
                continue;
            }

            $provider = $providers->forType($relatedType);
            if ($provider instanceof PivotAwareProviderInterface) {
                if ($provider->supportsPivot($relatedType, $relation)) {
                    self::checkEloquentJoin($providers, $type, $relation, $provider);
                }

                continue;
            }

            throw new \LogicException(\sprintf(
                'The relation "%s" on type "%s" declares stored pivot fields, but %s, the provider of its related type "%s", reads no pivot rows. Implement %s on it, so each member of the relation renders its meta.pivot.',
                $relation->name(),
                $type,
                $provider::class,
                $relatedType,
                PivotAwareProviderInterface::class,
            ));
        }
    }

    /**
     * A provider that queries the pivot rows reads them over the Eloquent `belongsToMany`
     * behind the relation, so on an Eloquent parent model the relation's method must be one.
     *
     * @param PivotAwareProviderInterface<object> $provider
     *
     * @throws \LogicException when the parent model's relation method is not an Eloquent belongsToMany
     */
    private static function checkEloquentJoin(DataProviderRegistry $providers, string $type, BelongsToMany $relation, PivotAwareProviderInterface $provider): void
    {
        $parentProvider = $providers->supportsType($type) ? $providers->forType($type) : null;
        $class = $parentProvider instanceof EloquentDataProvider ? $parentProvider->modelClassFor($type) : null;
        if ($class === null) {
            return;
        }

        $model = new $class();
        $method = $relation->column() ?? $relation->name();
        $eloquent = \method_exists($model, $method) ? $model->{$method}() : null;
        if ($eloquent instanceof EloquentBelongsToMany) {
            return;
        }

        throw new \LogicException(\sprintf(
            'The relation "%s" on type "%s" declares stored pivot fields, which %s reads over the relation\'s join, but %s::%s() is %s, not an Eloquent belongsToMany. Return a belongsToMany from %s(), or declare the relation without stored pivot fields.',
            $relation->name(),
            $type,
            $provider::class,
            $class,
            $method,
            \is_object($eloquent) ? 'a ' . $eloquent::class : 'missing',
            $method,
        ));
    }
}
