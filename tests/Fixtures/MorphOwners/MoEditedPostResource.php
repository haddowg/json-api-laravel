<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphMany;

/**
 * Reads notes through a `MorphTo` whose model method is no `morphTo`.
 */
final class MoEditedPostResource extends AbstractResource
{
    public static string $type = 'moPosts';

    public function fields(): array
    {
        return [Id::make()->numeric(), MorphMany::make('edited', 'moNotes', 'editor')];
    }
}
