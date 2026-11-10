<?php

declare(strict_types=1);

namespace Workbench\App\Positioned;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A song, whose slot in its queue only the queue's linkage sets.
 */
final class SongResource extends AbstractResource
{
    public static string $type = 'songs';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Integer::make('slot')->readOnly()->nullable(),
        ];
    }
}
