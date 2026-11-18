<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `fk-secrets`, which the Eloquent model's global scope narrows to the rows not hidden.
 */
final class FkSecretResource extends AbstractResource
{
    public static string $type = 'fk-secrets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
            Str::make('owner'),
        ];
    }
}
