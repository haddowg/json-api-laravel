<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\PivotAwareProviderInterface;

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
}
