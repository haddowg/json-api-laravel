<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\EntitySeam;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A `notes` resource whose `title` carries a custom {@see UniqueNoteTitle} entity
 * constraint — the fixture exercising the retained post-hydration
 * {@see \haddowg\JsonApiLaravel\Validation\EntityConstraintInterface} seam (PLAN decision
 * 6) end to end.
 */
#[AsJsonApiResource]
final class NoteResource extends AbstractResource
{
    public static string $type = 'notes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->constrain(new UniqueNoteTitle()),
        ];
    }
}
