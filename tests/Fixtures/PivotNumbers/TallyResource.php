<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotNumbers;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A named board whose `marks` carry an integer and a number pivot field, written through a
 * hook that records the PHP type of each pivot value it receives.
 *
 * @internal
 */
final class TallyResource extends AbstractResource
{
    public static string $type = 'tallies';

    /**
     * The pivot value types each written member carried, in write order.
     *
     * @var list<array<string, string>>
     */
    public static array $received = [];

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required(),
            MorphToMany::make('marks', ['notes'])
                ->identifiedBy(static fn(mixed $tally): array => [])
                ->fields(Integer::make('rank'), Decimal::make('weight'))
                ->fillUsing(static function (mixed $tally, mixed $linkage): mixed {
                    \assert($linkage instanceof ToManyRelationship);
                    foreach ($linkage->resourceIdentifiers as $identifier) {
                        $pivot = $identifier->meta['pivot'] ?? [];
                        self::$received[] = \is_array($pivot) ? \array_map(\get_debug_type(...), $pivot) : [];
                    }

                    return $tally;
                }),
        ];
    }
}
