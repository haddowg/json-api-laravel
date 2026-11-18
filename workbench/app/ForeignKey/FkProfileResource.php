<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `fk-profiles`, at most one a user.
 */
final class FkProfileResource extends AbstractResource
{
    public static string $type = 'fk-profiles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('bio'),
            Str::make('owner'),
        ];
    }
}
