<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\OpenApi;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiLaravel\OpenApi\PivotFields;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PivotFields::class)]
final class PivotFieldsTest extends TestCase
{
    #[Test]
    public function onlyAStoredPivotFieldIsASortKey(): void
    {
        $relation = BelongsToMany::make('tracks', 'tracks')->fields(
            Integer::make('position')->storedAs('pos'),
            Str::make('label')->computedUsing(static fn(): string => 'x'),
            Integer::make('rank')->computed(),
            // An extract hook changes only how a stored field is read.
            Integer::make('weight')->extractUsing(static fn(): int => 1),
        )->build();

        self::assertSame(
            [['position', 'pos'], ['weight', 'weight']],
            \array_map(static fn(SortByField $sort): array => [$sort->key, $sort->column], PivotFields::sortsFor($relation)),
        );
        self::assertSame([], PivotFields::sortsFor(HasMany::make('tracks', 'tracks')->build()));
    }
}
