<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Validation;

use haddowg\JsonApi\Resource\Constraint\After;
use haddowg\JsonApi\Resource\Constraint\AtLeastOneOf;
use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Each;
use haddowg\JsonApi\Resource\Constraint\EmailFormat;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMax;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMin;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Constraint\IpFormat;
use haddowg\JsonApi\Resource\Constraint\Max;
use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\MaxLength;
use haddowg\JsonApi\Resource\Constraint\Min;
use haddowg\JsonApi\Resource\Constraint\MinItems;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Constraint\MultipleOf;
use haddowg\JsonApi\Resource\Constraint\NotIn;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\Sequentially;
use haddowg\JsonApi\Resource\Constraint\SlugFormat;
use haddowg\JsonApi\Resource\Constraint\UlidFormat;
use haddowg\JsonApi\Resource\Constraint\UrlFormat;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApi\Resource\Constraint\When;
use haddowg\JsonApiLaravel\Validation\ConstraintTranslator;
use haddowg\JsonApiLaravel\Validation\Rules\BlankString;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Laravel skips every non-implicit rule for an empty or whitespace-only string. The
 * translator pairs each constraint with an implicit {@see BlankString} check so those
 * strings get the outcome the Symfony bundle gives them for the same declaration, whether
 * the constraint is declared directly or inside `Each`, `Sequentially`, `AtLeastOneOf` or
 * `When`. The expected outcomes were taken from the bundle's translator under
 * symfony/validator.
 *
 * @internal
 */
#[CoversClass(ConstraintTranslator::class)]
#[CoversClass(BlankString::class)]
final class BlankStringParityTest extends Orchestra
{
    /**
     * @return iterable<string, array{ConstraintInterface, mixed, bool}>
     */
    public static function cases(): iterable
    {
        $whenTrue = static fn(): bool => true;

        // [constraint, value, the bundle rejects it]
        yield 'minLength(1) empty' => [new MinLength(1), '', true];
        yield 'minLength(1) whitespace' => [new MinLength(1), '   ', false];
        yield 'minLength(3) whitespace' => [new MinLength(3), '   ', false];
        yield 'minLength(4) whitespace' => [new MinLength(4), '   ', true];
        yield 'minLength(0) empty' => [new MinLength(0), '', false];
        yield 'maxLength(2) empty' => [new MaxLength(2), '', false];
        yield 'maxLength(2) whitespace' => [new MaxLength(2), '   ', true];

        yield 'pattern empty' => [new Pattern('^[a-z]+$'), '', false];
        yield 'pattern whitespace' => [new Pattern('^[a-z]+$'), '   ', true];
        yield 'whitespace pattern whitespace' => [new Pattern('^\s*$'), '   ', false];
        yield 'slug empty' => [new SlugFormat(), '', false];
        yield 'slug whitespace' => [new SlugFormat(), '   ', true];

        yield 'email empty' => [new EmailFormat(), '', false];
        yield 'email whitespace' => [new EmailFormat(), '   ', true];
        yield 'url whitespace' => [new UrlFormat(), '   ', true];
        yield 'uuid whitespace' => [new UuidFormat(), '   ', true];
        yield 'uuid v4 empty' => [new UuidFormat(4), '', false];
        yield 'uuid v4 whitespace' => [new UuidFormat(4), '   ', true];
        yield 'ulid whitespace' => [new UlidFormat(), '   ', true];
        yield 'ip whitespace' => [new IpFormat(), '   ', true];

        yield 'in empty' => [new In(['a']), '', true];
        yield 'in whitespace' => [new In(['a']), '   ', true];
        yield 'in listing empty' => [new In(['']), '', false];
        yield 'notIn listing empty' => [new NotIn(['']), '', true];
        yield 'notIn whitespace' => [new NotIn(['']), '   ', false];

        yield 'min empty' => [new Min(1), '', true];
        yield 'exclusiveMin empty' => [new ExclusiveMin(0), '', true];
        yield 'multipleOf whitespace' => [new MultipleOf(2), '   ', true];
        yield 'minItems empty' => [new MinItems(1), '', true];
        yield 'maxItems whitespace' => [new MaxItems(1), '   ', true];

        yield 'each item empty' => [new Each([new MinLength(1)]), [''], true];
        yield 'each item whitespace' => [new Each([new MinLength(1)]), ['   '], false];
        yield 'each item whitespace pattern' => [new Each([new Pattern('^[a-z]+$')]), ['   '], true];
        yield 'sequentially empty' => [new Sequentially([new MinLength(1)]), '', true];
        yield 'sequentially whitespace' => [new Sequentially([new MinLength(1)]), '   ', false];
        yield 'sequentially pattern whitespace' => [new Sequentially([new MinLength(3), new Pattern('^[a-z]+$')]), '   ', true];
        yield 'atLeastOneOf empty' => [new AtLeastOneOf([new MinLength(1), new In(['x'])]), '', true];
        yield 'atLeastOneOf whitespace' => [new AtLeastOneOf([new MinLength(1), new In(['x'])]), '   ', false];
        yield 'when empty' => [new When($whenTrue, [new MinLength(1)]), '', true];
        yield 'when whitespace' => [new When($whenTrue, [new MinLength(1)]), '   ', false];
        yield 'when email whitespace' => [new When($whenTrue, [new EmailFormat()]), '   ', true];
        yield 'when skipped' => [new When(static fn(): bool => false, [new MinLength(1)]), '', false];
        yield 'when inside each' => [new Each([new When($whenTrue, [new MinLength(1)])]), [''], true];

        yield 'after empty' => [new After(new \DateTimeImmutable('2999-01-01')), '', false];
        // Both packages read the value with `new \DateTimeImmutable()`. Some PHP releases
        // parse "   " as now and others reject it, which skips the bound in both.
        yield 'after whitespace' => [new After(new \DateTimeImmutable('2999-01-01')), '   ', self::parsesAsDate('   ')];
    }

    private static function parsesAsDate(string $value): bool
    {
        try {
            new \DateTimeImmutable($value);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    #[Test]
    #[DataProvider('cases')]
    public function aBlankStringGetsTheBundlesOutcome(ConstraintInterface $constraint, mixed $value, bool $rejected): void
    {
        $rules = (new ConstraintTranslator())->translate($constraint);

        self::assertSame($rejected, $this->fails($rules, $value));
    }

    #[Test]
    public function anUpperBoundStillAcceptsABlankStringLikeTheBundle(): void
    {
        // symfony/validator compares "" <= 5 as strings, which holds, so the bundle accepts
        // a blank string under an upper bound. Laravel's numeric rule would reject it, so
        // the upper bounds get no blank-string check.
        foreach ([new Max(5), new ExclusiveMax(5)] as $constraint) {
            $rules = (new ConstraintTranslator())->translate($constraint);

            self::assertFalse($this->fails($rules, ''));
            self::assertFalse($this->fails($rules, '   '));
        }
    }

    #[Test]
    public function nullAndAbsentValuesAreLeftToThePresenceRules(): void
    {
        $rules = (new ConstraintTranslator())->translate(new MinLength(1));

        self::assertFalse($this->fails(['nullable', ...$rules], null));

        $app = $this->app;
        \assert($app !== null);
        /** @var ValidationFactory $factory */
        $factory = $app->make(ValidationFactory::class);
        self::assertFalse($factory->make([], ['value' => $rules])->fails());
    }

    /**
     * @param list<mixed> $rules
     */
    private function fails(array $rules, mixed $value): bool
    {
        $app = $this->app;
        \assert($app !== null);
        /** @var ValidationFactory $factory */
        $factory = $app->make(ValidationFactory::class);

        return $factory->make(['value' => $value], ['value' => ['sometimes', ...$rules]])->fails();
    }
}
