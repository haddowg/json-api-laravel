<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * One resource class on both servers, so both enforce its policy.
 */
#[AsJsonApiResource(server: ['default', 'admin'], policy: AdminNotePolicy::class)]
final class LabelResource extends AbstractResource
{
    public static string $type = 'labels';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
