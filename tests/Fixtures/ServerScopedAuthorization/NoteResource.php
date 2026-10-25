<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `notes` on the `default` server, declaring no policy.
 */
final class NoteResource extends AbstractResource
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
