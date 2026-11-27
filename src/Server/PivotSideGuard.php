<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;

/**
 * Checks the sides a `belongsToMany` names with `through(parent:, related:)` against the
 * Eloquent relation behind it. The provider reads the sides from the relation's foreign and
 * related pivot keys, so a name must be the key of its side, or a `belongsTo` of the pivot
 * model on that key. A relation that names no side is not checked.
 *
 * @internal
 */
final class PivotSideGuard
{
    /**
     * @param \Closure(): DataProviderRegistry $providers
     */
    public function __construct(private readonly \Closure $providers) {}

    /**
     * @throws \LogicException when a named side is not the side the Eloquent relation keys
     */
    public function check(string $type, AbstractResource $resource): void
    {
        $providers = null;
        foreach ($resource->allFields() as $relation) {
            if (!$relation instanceof BelongsToMany || ($relation->pivotParentSide() === null && $relation->pivotRelatedSide() === null)) {
                continue;
            }

            $providers ??= ($this->providers)();
            $provider = $providers->supportsType($type) ? $providers->forType($type) : null;
            $class = $provider instanceof EloquentDataProvider ? $provider->modelClassFor($type) : null;
            if ($class === null) {
                continue;
            }

            $model = new $class();
            \assert($model instanceof Model);
            $method = $relation->column() ?? $relation->name();
            $eloquent = \method_exists($model, $method) ? $model->{$method}() : null;
            if (!$eloquent instanceof EloquentBelongsToMany || $eloquent instanceof EloquentMorphToMany) {
                throw new \LogicException(\sprintf(
                    'The relation "%s" on type "%s" names the sides of its pivot rows in through(), but %s::%s() is not an Eloquent belongsToMany, whose pivot keys decide them.',
                    $relation->name(),
                    $type,
                    $class,
                    $method,
                ));
            }

            self::checkSide($relation, $type, $class, $method, $eloquent, 'parent', $relation->pivotParentSide(), $eloquent->getForeignPivotKeyName());
            self::checkSide($relation, $type, $class, $method, $eloquent, 'related', $relation->pivotRelatedSide(), $eloquent->getRelatedPivotKeyName());
        }
    }

    /**
     * @param EloquentBelongsToMany<Model, Model> $eloquent
     */
    private static function checkSide(BelongsToMany $relation, string $type, string $class, string $method, EloquentBelongsToMany $eloquent, string $side, ?string $named, string $key): void
    {
        if ($named === null || $named === $key) {
            return;
        }

        $pivot = $eloquent->newPivot();
        $held = \method_exists($pivot, $named) ? $pivot->{$named}() : null;
        if ($held instanceof BelongsTo && $held->getForeignKeyName() === $key) {
            return;
        }

        throw new \LogicException(\sprintf(
            'The relation "%s" on type "%s" declares through(%s: \'%s\'), but %s::%s() keys its %s side by the pivot column "%s" of %s. Name "%s", or a belongsTo of the pivot model on that column.',
            $relation->name(),
            $type,
            $side,
            $named,
            $class,
            $method,
            $side,
            $key,
            $eloquent->getTable(),
            $key,
        ));
    }
}
