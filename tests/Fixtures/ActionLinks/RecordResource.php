<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionLinks;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Link\ActionLink;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use Workbench\App\Models\Album;

/**
 * A `records` type whose action links the tests switch by {@see $variant}.
 *
 * - `review` has no `asLink`, so the declaration alone links it, while the record is
 *   `released`;
 * - `feature` is gated by its ability and links only released records;
 * - `archive` sets `asLink`, and its declaration narrows it to withdrawn records.
 *
 * The `missing`, `clash` and `duplicate` variants each misdeclare one link.
 */
#[AsJsonApiResource(readOnly: true)]
final class RecordResource extends AbstractResource
{
    public static string $type = 'records';

    public static string $variant = 'test';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('status'),
        ];
    }

    public function links(): array
    {
        return match (self::$variant) {
            'missing' => [ActionLink::for('retract')],
            'clash' => [
                ActionLink::for('review'),
                DeclaredLink::make('review')->hrefUsing(static fn(mixed $record, JsonApiRequestInterface $request): ?string => null),
            ],
            'duplicate' => [ActionLink::for('review'), ActionLink::for('review')],
            default => [
                ActionLink::for('review')
                    ->when(static fn(Record|Album $record, JsonApiRequestInterface $request): bool => $record->status === 'released')
                    ->describedAs('Present while the record is released.'),
                ActionLink::for('feature')
                    ->when(static fn(Record|Album $record, JsonApiRequestInterface $request): bool => $record->status === 'released'),
                ActionLink::for('archive')
                    ->when(static fn(Record|Album $record, JsonApiRequestInterface $request): bool => $record->status === 'withdrawn')
                    ->describedAs('Present while the record is withdrawn.'),
            ],
        };
    }
}
