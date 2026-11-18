<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\FieldState;
use haddowg\JsonApi\Resource\Field\ForeignKey;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\RelationForeignKeyValidator;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataPersister\WritesForeignKeysInterface;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\ForeignKeyReads;
use haddowg\JsonApiLaravel\DataProvider\ReadsByForeignKeyInterface;
use haddowg\JsonApiLaravel\Operation\ForeignKeyWrites;
use Illuminate\Database\Eloquent\Model;

/**
 * Checks that every relation of a resource can reach its rows. Route registration checks the
 * relations declared with a foreign key, and `jsonapi:optimize` checks them all:
 *
 *  - a relation declared with a foreign key names a stored field or a to-one relation to the
 *    parent's type on its related type (core's {@see RelationForeignKeyValidator}), which the
 *    related type's provider reads through ({@see ReadsByForeignKeyInterface}) and, when the
 *    parent can be written, its persister writes ({@see WritesForeignKeysInterface}). On an
 *    Eloquent related type a to-one foreign key must be a `BelongsTo` of its model;
 *  - a to-many of a parent no Eloquent model serves, into an Eloquent type whose model has
 *    global scopes, declares a foreign key or `extractUsing()`: the model's own value is read
 *    otherwise, and no scope narrows it. A `BelongsToMany` with stored pivot fields reads its
 *    pivot rows through the parent's model, so it can't be declared on such a parent at all.
 */
final class ForeignKeyGuard
{
    /**
     * @param \Closure(): DataProviderRegistry   $providers
     * @param \Closure(): ?DataPersisterRegistry $persisters
     */
    public function __construct(
        private readonly \Closure $providers,
        private readonly \Closure $persisters,
    ) {}

    /**
     * Checks every relation of `$resource`. With `$unkeyed` off, as route registration runs it,
     * only relations declared with a foreign key are checked, so a resource declaring none
     * builds no provider.
     *
     * @throws \LogicException when a relation of `$resource` can't reach its rows
     */
    public function check(SerializerResolverInterface $resolver, string $type, AbstractResource $resource, bool $unkeyed = true): void
    {
        (new RelationForeignKeyValidator($resolver))->validate($type, $resource);

        $providers = null;
        foreach ($resource->allFields() as $relation) {
            if (!$relation instanceof RelationInterface || \count($relation->relatedTypes()) !== 1) {
                continue;
            }

            $foreignKey = ForeignKey::nameOf($relation);
            if ($foreignKey === null && !$unkeyed) {
                continue;
            }

            $providers ??= ($this->providers)();
            $relatedType = $relation->relatedTypes()[0];
            if (!$providers->supportsType($relatedType)) {
                continue;
            }

            if ($foreignKey !== null) {
                $this->checkForeignKey($resolver, $providers, $type, $relation, $relatedType, $foreignKey);

                continue;
            }

            $this->checkUnkeyed($providers, $type, $relation, $relatedType);
        }
    }

    private function checkForeignKey(SerializerResolverInterface $resolver, DataProviderRegistry $providers, string $type, RelationInterface $relation, string $relatedType, string $name): void
    {
        $provider = $providers->forType($relatedType);
        if (!$provider instanceof ReadsByForeignKeyInterface) {
            throw ForeignKeyReads::unreadable($relation, $type, $name, $provider, $relatedType);
        }

        $persisters = ($this->persisters)();
        if ($persisters !== null && $persisters->supportsType($type) && !$relation->isReadOnly(false)) {
            $writer = $persisters->supportsType($relatedType) ? $persisters->forType($relatedType) : null;
            if (!$writer instanceof WritesForeignKeysInterface) {
                throw ForeignKeyWrites::unwritable($relation, $type, $name, $writer, $relatedType);
            }
        }

        $foreignKey = ForeignKey::of($relation, $resolver);
        $class = $provider instanceof EloquentDataProvider ? $provider->modelClassFor($relatedType) : null;
        if ($foreignKey === null || !$foreignKey->isRelation() || $class === null) {
            return;
        }

        $model = new $class();
        \assert($model instanceof Model);
        if (EloquentDataProvider::belongsToOf($model, $foreignKey->column) === null) {
            throw new \LogicException(\sprintf(
                'Relation "%s" on resource type "%s" reads through the foreign key "%s", a to-one relation of type "%s", but %s has no BelongsTo method "%s" holding it.',
                $relation->name(),
                $type,
                $name,
                $relatedType,
                $class,
                $foreignKey->column,
            ));
        }
    }

    private function checkUnkeyed(DataProviderRegistry $providers, string $type, RelationInterface $relation, string $relatedType): void
    {
        if (!$relation instanceof HasMany || self::servedByModel($providers, $type)) {
            return;
        }

        $related = $providers->forType($relatedType);
        $class = $related instanceof EloquentDataProvider ? $related->modelClassFor($relatedType) : null;
        if ($class === null) {
            return;
        }

        if ($relation instanceof BelongsToMany && $relation->storedPivotFields() !== []) {
            throw new \LogicException(\sprintf(
                'Relation "%s" on resource type "%s" is a BelongsToMany with stored pivot fields, which reads its pivot rows through the parent\'s model, but no Eloquent model serves "%s". Serve "%s" from a model, or declare the relation as a HasMany with a foreign key.',
                $relation->name(),
                $type,
                $type,
                $type,
            ));
        }

        $model = new $class();
        \assert($model instanceof Model);
        if (self::extractsValue($relation) || $model->getGlobalScopes() === []) {
            return;
        }

        throw new \LogicException(\sprintf(
            'Relation "%s" on resource type "%s" reads type "%s", whose model %s has global scopes, but no Eloquent model serves "%s", so no relation query applies those scopes to the parent\'s members. %s',
            $relation->name(),
            $type,
            $relatedType,
            $class,
            $type,
            $relation instanceof BelongsToMany
                ? 'Compute its members with extractUsing().'
                : \sprintf(
                    'Name the field or to-one relation of "%s" holding the parent\'s id as the foreign key argument, HasMany::make(\'%s\', \'%s\', \'<foreign key>\'), or compute it with extractUsing().',
                    $relatedType,
                    $relation->name(),
                    $relatedType,
                ),
        ));
    }

    private static function servedByModel(DataProviderRegistry $providers, string $type): bool
    {
        if (!$providers->supportsType($type)) {
            return false;
        }

        $provider = $providers->forType($type);

        return $provider instanceof EloquentDataProvider && $provider->modelClassFor($type) !== null;
    }

    /**
     * Whether `$relation` computes its value with an `extractUsing()` closure, which core keeps
     * on the field's state.
     */
    private static function extractsValue(RelationInterface $relation): bool
    {
        if (!$relation instanceof AbstractFieldValue) {
            return false;
        }

        $state = (new \ReflectionProperty(AbstractFieldValue::class, 'state'))->getValue($relation);

        return $state instanceof FieldState && $state->extractUsing !== null;
    }
}
