<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\AtomicServerScope;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A writable type exposed only on the `default` server.
 */
#[AsJsonApiResource]
final class NoteResource extends AbstractResource
{
    public static string $type = 'atomic_note';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
