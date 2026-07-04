<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\EloquentRelations;

/**
 * The object-aware `getType` shared by the two member resources of a {@see Post}'s
 * polymorphic relations. It discriminates by the **Eloquent model class** so
 * {@see \haddowg\JsonApi\Resource\Field\RelationInterface::resolveSerializer()} picks
 * `authors` for an {@see Author} model and `tags` for a {@see Tag} model — decoupled from
 * the storage-side morph alias (`blog_author`/`blog_tag`), which the provider uses only to
 * resolve the model class when loading a {@see \Illuminate\Database\Eloquent\Relations\MorphTo}
 * (the phase-3a morph-mapping design, blueprint §3g).
 */
trait DiscriminatesBlogMember
{
    public function getType(mixed $object): string
    {
        return $object instanceof Author ? 'authors' : 'tags';
    }
}
