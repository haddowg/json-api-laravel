<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Overrides;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `notes` resource — the serializer-override witness (ADR 0014). Reads render
 * through the hand-written {@see NoteSerializer} (which takes a container-bound
 * constructor argument), while writes stay field-driven: this resource's `title`
 * field hydrates a `PATCH`, whose response then renders through the override again.
 *
 * @internal
 */
#[AsJsonApiResource(serializer: NoteSerializer::class)]
final class NoteResource extends AbstractResource
{
    public static string $type = 'notes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}
