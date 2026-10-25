<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The same `notes` type on the `admin` server, under a policy only an admin passes.
 */
#[AsJsonApiResource(server: 'admin', policy: AdminNotePolicy::class)]
final class AdminNoteResource extends AbstractResource
{
    public static string $type = 'notes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('body'),
        ];
    }
}
