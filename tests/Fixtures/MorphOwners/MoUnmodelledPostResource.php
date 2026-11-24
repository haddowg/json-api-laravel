<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;

/**
 * Reads the notes through their Eloquent `morphTo` while no model serves posts.
 */
final class MoUnmodelledPostResource extends AbstractResource
{
    public static string $type = 'moPosts';

    public function fields(): array
    {
        return [Id::make()->numeric(), HasMany::make('authoredNotes', 'moNotes', 'author')];
    }
}
