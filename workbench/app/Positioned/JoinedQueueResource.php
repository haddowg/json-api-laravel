<?php

declare(strict_types=1);

namespace Workbench\App\Positioned;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Integer;

/**
 * The Eloquent provider's queue: a {@see QueueResource} whose `favourites` keep a position on
 * their join rows, which the in-memory provider would refuse at warm-up.
 */
final class JoinedQueueResource extends AbstractResource
{
    public static string $type = 'queues';

    public function fields(): array
    {
        return [
            ...(new QueueResource())->fields(),
            BelongsToMany::make('favourites', 'songs')
                ->fields(Integer::make('position'))
                ->positionedBy('position'),
        ];
    }
}
