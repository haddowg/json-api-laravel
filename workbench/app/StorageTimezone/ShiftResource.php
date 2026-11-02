<?php

declare(strict_types=1);

namespace Workbench\App\StorageTimezone;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\DateTimeParser;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\Where;

final class ShiftResource extends AbstractResource
{
    public static string $type = 'shifts';

    public function fields(): array
    {
        return [
            Id::make()->sortable(),
            DateTime::make('startsAt')->storedAs('starts_at')->nullable()->sortable(),
            Date::make('day')->nullable(),
        ];
    }

    public function filters(): array
    {
        return [
            DateRange::make('startsRange', 'starts_at'),
            DateRange::make('dayRange', 'day'),
            Where::make('startsFrom', column: 'starts_at', operator: '>=')
                ->deserializeUsing(static fn(mixed $value): mixed => DateTimeParser::tryParse($value) ?? $value),
        ];
    }
}
