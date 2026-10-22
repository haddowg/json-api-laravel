<?php

declare(strict_types=1);

namespace Workbench\App\Timezone;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The output-timezone witness. `startsAt` follows the server's zone, `day` and
 * `doorsOpen` must not, and `startsAtInTokyo` reads the same column through a field that
 * sets its own zone, so it renders in Tokyo whatever the server says. The `performers`
 * pivot carries a `bookedAt` instant.
 */
#[AsJsonApiResource(readOnly: true, server: ['default', 'admin'])]
final class GigResource extends AbstractResource
{
    public static string $type = 'gigs';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            DateTime::make('startsAt')->storedAs('starts_at'),
            DateTime::make('startsAtInTokyo')->storedAs('starts_at')->outputTimezone('Asia/Tokyo'),
            Date::make('day'),
            Time::make('doorsOpen')->storedAs('doors_open'),
            BelongsTo::make('venue', 'venues'),
            BelongsToMany::make('performers', 'performers')->fields(
                DateTime::make('bookedAt')->storedAs('booked_at')->readOnly()->build(),
            ),
        ];
    }
}
