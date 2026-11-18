<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `fk-members`, each on a team.
 */
final class FkMemberResource extends AbstractResource
{
    public static string $type = 'fk-members';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->sortable(),
            BelongsTo::make('team', 'fk-teams')->nullable(),
        ];
    }
}
