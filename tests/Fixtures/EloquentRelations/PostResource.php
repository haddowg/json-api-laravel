<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\EloquentRelations;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `posts` resource for the Eloquent blog fixture — the parent of every 3a relation
 * cardinality on the reference provider (the Eloquent twin of the in-memory blog
 * {@see \haddowg\JsonApiLaravel\Tests\Fixtures\Relations\PostResource}): a monomorphic to-one
 * (`author`), a `belongsToMany` with a declared `position` pivot field (the pivot-READ
 * witness), a **polymorphic** to-one (`feature` → an author OR a tag, resolved through the
 * morph map), and a **polymorphic** to-many (`related` → a mixed author + tag set, read off
 * the parent's merged accessor via `extractUsing`).
 *
 * The relation NAME is the Eloquent relation method (`author`, `tags`, `feature`); the
 * mixed `related` set has no single native relation, so its `extractUsing` composes it from
 * {@see Post::relatedMembers()}.
 */
#[AsJsonApiResource(readOnly: true)]
final class PostResource extends AbstractResource
{
    public static string $type = 'posts';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsTo::make('author', 'authors'),
            BelongsToMany::make('tags', 'tags')->fields(
                // A read-only pivot field for the meta.pivot READ witness (pivot WRITE is 3b).
                Integer::make('position')->readOnly(),
            ),
            MorphTo::make('feature', ['authors', 'tags']),
            MorphToMany::make('related', ['authors', 'tags'])
                ->extractUsing(static fn(mixed $post): array => $post instanceof Post ? $post->relatedMembers() : []),
        ];
    }
}
