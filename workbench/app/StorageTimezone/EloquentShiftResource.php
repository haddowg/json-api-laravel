<?php

declare(strict_types=1);

namespace Workbench\App\StorageTimezone;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;

/**
 * The shifts resource on Eloquent, adding workers joined through a pivot whose
 * `checked_in_at` is a writable date-time.
 */
final class EloquentShiftResource extends ShiftResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            BelongsToMany::make('workers', 'workers')->fields(
                DateTime::make('checkedInAt')->storedAs('checked_in_at')->nullable()->build(),
            ),
        ];
    }
}
