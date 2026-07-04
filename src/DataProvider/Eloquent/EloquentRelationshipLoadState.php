<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The reference Eloquent load-state predicate — the storage twin of the bundle's
 * `DoctrineRelationshipLoadState` (PLAN decision 8). It answers, **without triggering a
 * load**, whether a relation's linkage is already in memory for a model, so a lazy
 * relation ({@see RelationInterface::emitsDataOnlyWhenLoaded()} — the per-type default:
 * a to-many, or a `HasOne`) can render links-only rather than force a lazy round-trip
 * just to emit identifiers.
 *
 * The answer is Eloquent's own {@see Model::relationLoaded()} — `true` exactly when the
 * relation has been {@see Model::setRelation()} onto the model (an eager load, or the
 * include batcher's write-back). This is why the include batcher's `setRelation`
 * write-back makes the load-state seam report loaded: the two are the same mechanism read
 * from both ends (blueprint §3e). It answers honestly for **every** cardinality — core
 * consults it only for a lazy relation (BelongsTo/MorphTo are eager and never asked), so a
 * uniform `relationLoaded()` is both correct and simpler than the Doctrine impl's
 * "to-one always loaded" special-case: an owner-side FK is already on the model, so its
 * to-one linkage never needs this predicate.
 *
 * The JSON:API relationship maps to its Eloquent relation method by the field's
 * {@see \haddowg\JsonApi\Resource\Field\FieldInterface::column()} `?? name()` (the backing
 * method name). A non-Eloquent model (an in-memory POPO reaching this predicate through a
 * mixed graph) is reported loaded, so the predicate never changes behaviour for a model it
 * cannot reason about — exactly as the standalone default (no predicate) treats every
 * relation as loaded.
 *
 * Wired through core's {@see \haddowg\JsonApi\Server\Server::withRelationshipLoadState()}
 * only on an Eloquent-backed server (the workbench binds it as
 * {@see RelationshipLoadStateInterface}); the in-memory witness leaves it unbound, so
 * every relation renders its linkage eagerly (the standalone default).
 */
final class EloquentRelationshipLoadState implements RelationshipLoadStateInterface
{
    public function isRelationshipLoaded(mixed $model, RelationInterface $relation): bool
    {
        if (!$model instanceof Model) {
            return true;
        }

        return $model->relationLoaded($relation->column() ?? $relation->name());
    }
}
