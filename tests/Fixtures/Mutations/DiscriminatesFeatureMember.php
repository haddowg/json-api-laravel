<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Mutations;

/**
 * The object-aware `getType` shared by the two member resources of a {@see PostResource}'s
 * polymorphic `feature` relation. It overrides
 * {@see \haddowg\JsonApi\Resource\AbstractResource::getType()} (which returns the static
 * `$type` regardless of the object) so
 * {@see \haddowg\JsonApi\Resource\Field\RelationInterface::resolveSerializer()} — which
 * discriminates by matching a member object's own `getType()` against each declared type —
 * picks `authors` for an author member and `tags` for a tag member, across BOTH providers
 * (the Eloquent {@see Author}/{@see Tag} models AND the in-memory {@see AuthorDomain}/
 * {@see TagDomain} POPOs).
 */
trait DiscriminatesFeatureMember
{
    public function getType(mixed $object): string
    {
        return $object instanceof Author || $object instanceof AuthorDomain ? 'authors' : 'tags';
    }
}
