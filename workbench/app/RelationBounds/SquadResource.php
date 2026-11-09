<?php

declare(strict_types=1);

namespace Workbench\App\RelationBounds;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A squad fields one or two players.
 */
final class SquadResource extends AbstractResource
{
    public static string $type = 'squads';

    public function fields(): array
    {
        return [
            Id::make()->allowClientId(),
            Str::make('name'),
            BelongsToMany::make('players', 'players')->minItems(1)->maxItems(2),
        ];
    }
}
