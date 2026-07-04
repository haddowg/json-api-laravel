<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Relations;

/**
 * The object-aware `getType` shared by the two member resources of a {@see Post}'s
 * polymorphic relations. It overrides {@see \haddowg\JsonApi\Resource\AbstractResource::getType()}
 * (which returns the static `$type` regardless of the object) so
 * {@see \haddowg\JsonApi\Resource\Field\RelationInterface::resolveSerializer()} — which
 * discriminates by matching a member object's own `getType()` against each declared type —
 * picks the right serializer per member: `authors` for an {@see Author}, `tags` for a
 * {@see Tag}. The morph discrimination is by object class, decoupled from any storage-side
 * morph alias (the phase-3a morph-mapping design).
 */
trait DiscriminatesBlogMember
{
    public function getType(mixed $object): string
    {
        return $object instanceof Author ? 'authors' : 'tags';
    }
}
