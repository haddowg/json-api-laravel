<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider\Eloquent;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentKeysetExpression;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EloquentKeysetExpression::class)]
final class EloquentKeysetExpressionTest extends TestCase
{
    private string $previousTimezone;

    protected function setUp(): void
    {
        $this->previousTimezone = \date_default_timezone_get();
        \date_default_timezone_set('America/New_York');
    }

    protected function tearDown(): void
    {
        \date_default_timezone_set($this->previousTimezone);
    }

    #[Test]
    public function aBoundaryBindsAsItsTokenFormByDefault(): void
    {
        $expression = EloquentKeysetExpression::make('CAST(v.value AS REAL)', ['cap']);

        self::assertSame(['cap'], $expression->bindings);
        self::assertSame(20.5, $expression->boundary(20.5));
        self::assertSame('2026-10-01T16:00:00.000000+00:00', $expression->boundary('2026-10-01T16:00:00.000000+00:00'));
    }

    #[Test]
    public function aDateTimeBoundaryKeepsTheOffsetItWasMintedIn(): void
    {
        $bound = EloquentKeysetExpression::make('v.ends_at')->asDateTime()->boundary('2026-10-01T16:00:00.000000+00:00');

        self::assertInstanceOf(\DateTimeImmutable::class, $bound);
        self::assertSame('2026-10-01 16:00:00 +00:00', $bound->format('Y-m-d H:i:s P'));
    }

    #[Test]
    public function aNaiveDateTimeBoundaryMovesIntoTheDefaultTimezone(): void
    {
        $bound = EloquentKeysetExpression::make('v.ends_at')->asNaiveDateTime()->boundary('2026-10-01T16:00:00.000000+00:00');

        self::assertInstanceOf(\DateTimeImmutable::class, $bound);
        self::assertSame('2026-10-01 12:00:00', $bound->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function aBoundaryThatIsNotADateBindsVerbatim(): void
    {
        $expression = EloquentKeysetExpression::make('v.ends_at')->asNaiveDateTime();

        self::assertSame('not a date', $expression->boundary('not a date'));
        self::assertSame(7, $expression->boundary(7));
    }

    #[Test]
    public function aCastReplacesTheBinding(): void
    {
        $expression = EloquentKeysetExpression::make('v.day')->castUsing(static fn(string|int|float|bool $value): string => \substr((string) $value, 0, 10));

        self::assertSame('2026-10-01', $expression->boundary('2026-10-01T00:00:00.000000+00:00'));
    }

    #[Test]
    public function anExpressionObjectRendersThroughTheGrammar(): void
    {
        $grammar = new SQLiteGrammar(new SQLiteConnection(new \PDO('sqlite::memory:')));

        self::assertSame('lower(v.value)', EloquentKeysetExpression::make(new Expression('lower(v.value)'))->sql($grammar));
        self::assertSame('v.value', EloquentKeysetExpression::make('v.value')->sql($grammar));
    }
}
