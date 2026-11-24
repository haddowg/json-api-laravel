<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;

/**
 * Names the bare id field of the notes' polymorphic owner, which holds the ids of posts and
 * videos alike.
 */
final class MoBareIdPostResource extends AbstractResource
{
    public static string $type = 'moPosts';

    public function fields(): array
    {
        return [Id::make()->numeric(), HasMany::make('notes', 'moNotes', 'ownerId')];
    }
}
