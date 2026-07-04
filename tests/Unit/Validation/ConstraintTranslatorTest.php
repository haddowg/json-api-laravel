<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Validation;

use haddowg\JsonApi\Resource\Constraint\After;
use haddowg\JsonApi\Resource\Constraint\AtLeastOneOf;
use haddowg\JsonApi\Resource\Constraint\Before;
use haddowg\JsonApi\Resource\Constraint\Between;
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
use haddowg\JsonApi\Resource\Constraint\MaxProperties;
use haddowg\JsonApi\Resource\Constraint\Min;
use haddowg\JsonApi\Resource\Constraint\MinItems;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Constraint\MinProperties;
use haddowg\JsonApi\Resource\Constraint\MultipleOf;
use haddowg\JsonApi\Resource\Constraint\NotIn;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\Sequentially;
use haddowg\JsonApi\Resource\Constraint\SlugFormat;
use haddowg\JsonApi\Resource\Constraint\UlidFormat;
use haddowg\JsonApi\Resource\Constraint\UniqueItems;
use haddowg\JsonApi\Resource\Constraint\UrlFormat;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApi\Resource\Constraint\When;
use haddowg\JsonApiLaravel\Validation\ConstraintTranslator;
use haddowg\JsonApiLaravel\Validation\ConstraintTranslatorInterface;
use haddowg\JsonApiLaravel\Validation\Rules\AfterDate;
use haddowg\JsonApiLaravel\Validation\Rules\AtLeastOneOf as AtLeastOneOfRule;
use haddowg\JsonApiLaravel\Validation\Rules\BeforeDate;
use haddowg\JsonApiLaravel\Validation\Rules\BetweenDates;
use haddowg\JsonApiLaravel\Validation\Rules\DistinctArray;
use haddowg\JsonApiLaravel\Validation\Rules\EachElement;
use haddowg\JsonApiLaravel\Validation\Rules\GreaterThan;
use haddowg\JsonApiLaravel\Validation\Rules\LessThan;
use haddowg\JsonApiLaravel\Validation\Rules\UuidVersion;
use haddowg\JsonApiLaravel\Validation\Rules\WhenRule;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Rules\In as InRule;
use Illuminate\Validation\Rules\NotIn as NotInRule;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Each core constraint value object maps to the `illuminate/validation` rule(s) that
 * enforce it, and the produced rules actually have teeth (a bad value fails with a
 * non-empty, localizable message; a good value passes). The composition and
 * closure-carrying constraints map to the shipped invokable rules; a constraint outside
 * the built-in vocabulary is delegated to the class-keyed extension point, and — absent a
 * translator — fails loud.
 *
 * @internal
 */
#[CoversClass(ConstraintTranslator::class)]
final class ConstraintTranslatorTest extends Orchestra
{
    private ConstraintTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translator = new ConstraintTranslator();
    }

    #[Test]
    public function itTranslatesTheNumericBounds(): void
    {
        self::assertSame(['numeric', 'min:5'], $this->translator->translate(new Min(5)));
        self::assertSame(['numeric', 'max:5'], $this->translator->translate(new Max(5)));
        self::assertSame(['numeric', 'multiple_of:3'], $this->translator->translate(new MultipleOf(3)));

        self::assertTrue($this->fails($this->translator->translate(new Min(5)), 4));
        self::assertFalse($this->fails($this->translator->translate(new Min(5)), 5));
        self::assertTrue($this->fails($this->translator->translate(new MultipleOf(3)), 4));
    }

    #[Test]
    public function itShipsTheExclusiveNumericBoundsAsInvokableRules(): void
    {
        $min = $this->translator->translate(new ExclusiveMin(5));
        $max = $this->translator->translate(new ExclusiveMax(5));

        self::assertInstanceOf(GreaterThan::class, $min[0]);
        self::assertInstanceOf(LessThan::class, $max[0]);

        // Exclusive: the bound itself fails.
        self::assertTrue($this->fails($min, 5));
        self::assertFalse($this->fails($min, 6));
        self::assertTrue($this->fails($max, 5));
        self::assertFalse($this->fails($max, 4));
    }

    #[Test]
    public function itTranslatesTheStringLengthBoundsWithAStringInterpretation(): void
    {
        self::assertSame(['string', 'min:3'], $this->translator->translate(new MinLength(3)));
        self::assertSame(['string', 'max:3'], $this->translator->translate(new MaxLength(3)));

        self::assertTrue($this->fails($this->translator->translate(new MinLength(3)), 'ab'));
        self::assertFalse($this->fails($this->translator->translate(new MinLength(3)), 'abc'));
        // A numeric-looking string is measured by length, not value (no numeric rule).
        self::assertTrue($this->fails($this->translator->translate(new MinLength(3)), '12'));
    }

    #[Test]
    public function itTranslatesTheCollectionCounts(): void
    {
        self::assertSame(['array', 'min:2'], $this->translator->translate(new MinItems(2)));
        self::assertSame(['array', 'max:2'], $this->translator->translate(new MaxItems(2)));
        self::assertSame(['array', 'min:2'], $this->translator->translate(new MinProperties(2)));
        self::assertSame(['array', 'max:2'], $this->translator->translate(new MaxProperties(2)));

        self::assertTrue($this->fails($this->translator->translate(new MinItems(2)), ['a']));
        self::assertFalse($this->fails($this->translator->translate(new MinItems(2)), ['a', 'b']));
    }

    #[Test]
    public function itShipsUniqueItemsAsADistinctArrayRule(): void
    {
        $rules = $this->translator->translate(new UniqueItems());
        self::assertInstanceOf(DistinctArray::class, $rules[0]);

        self::assertTrue($this->fails($rules, ['a', 'a']));
        self::assertFalse($this->fails($rules, ['a', 'b']));
    }

    #[Test]
    public function itTranslatesTheStringFormats(): void
    {
        self::assertSame(['email'], $this->translator->translate(new EmailFormat()));
        self::assertSame(['email:strict'], $this->translator->translate(new EmailFormat(true)));
        self::assertSame(['url'], $this->translator->translate(new UrlFormat()));
        self::assertSame(['url:https'], $this->translator->translate(new UrlFormat(['https'])));
        self::assertSame(['uuid'], $this->translator->translate(new UuidFormat()));
        self::assertSame(['ulid'], $this->translator->translate(new UlidFormat()));
        self::assertSame(['ipv4'], $this->translator->translate(new IpFormat(4)));
        self::assertSame(['ipv6'], $this->translator->translate(new IpFormat(6)));
        self::assertSame(['ip'], $this->translator->translate(new IpFormat()));

        self::assertTrue($this->fails($this->translator->translate(new EmailFormat()), 'not-an-email'));
        self::assertFalse($this->fails($this->translator->translate(new EmailFormat()), 'a@b.com'));
    }

    #[Test]
    public function itShipsAVersionSpecificUuidAsAnInvokableRule(): void
    {
        $rules = $this->translator->translate(new UuidFormat(4));
        self::assertInstanceOf(UuidVersion::class, $rules[0]);

        self::assertTrue($this->fails($rules, '00000000-0000-1000-8000-000000000000')); // v1, not v4
        self::assertFalse($this->fails($rules, '00000000-0000-4000-8000-000000000000')); // v4
    }

    #[Test]
    public function itTranslatesPatternAndSlugToADelimitedRegexRule(): void
    {
        self::assertSame(['regex:~[a-z]+~'], $this->translator->translate(new Pattern('[a-z]+')));
        self::assertSame(
            ['regex:~^[a-z0-9]+(?:-[a-z0-9]+)*$~'],
            $this->translator->translate(new SlugFormat()),
        );

        self::assertTrue($this->fails($this->translator->translate(new Pattern('^[0-9]{5}$')), 'ABCDE'));
        self::assertFalse($this->fails($this->translator->translate(new Pattern('^[0-9]{5}$')), '12345'));
    }

    #[Test]
    public function itTranslatesTheEnumMembershipsToRuleObjects(): void
    {
        $in = $this->translator->translate(new In(['a', 'b']));
        $notIn = $this->translator->translate(new NotIn(['a']));

        self::assertInstanceOf(InRule::class, $in[0]);
        self::assertInstanceOf(NotInRule::class, $notIn[0]);

        self::assertTrue($this->fails($in, 'c'));
        self::assertFalse($this->fails($in, 'a'));
        self::assertTrue($this->fails($notIn, 'a'));
        self::assertFalse($this->fails($notIn, 'b'));
    }

    #[Test]
    public function itShipsEachAsAnArrayElementRule(): void
    {
        $rules = $this->translator->translate(new Each([new MinLength(3)]));
        self::assertInstanceOf(EachElement::class, $rules[0]);

        self::assertTrue($this->fails($rules, ['abc', 'ab']));
        self::assertFalse($this->fails($rules, ['abc', 'defg']));
    }

    #[Test]
    public function itTranslatesSequentiallyToABailLedRuleSet(): void
    {
        self::assertSame(
            ['bail', 'string', 'min:3'],
            $this->translator->translate(new Sequentially([new MinLength(3)])),
        );
    }

    #[Test]
    public function itShipsAtLeastOneOfAsAnOrCombinatorRule(): void
    {
        $rules = $this->translator->translate(new AtLeastOneOf([new MinLength(10), new In(['ok'])]));
        self::assertInstanceOf(AtLeastOneOfRule::class, $rules[0]);

        // Passes the second alternative (In), fails the first (length) — one is enough.
        self::assertFalse($this->fails($rules, 'ok'));
        // Fails both alternatives.
        self::assertTrue($this->fails($rules, 'no'));
    }

    #[Test]
    public function itShipsWhenAsAConditionalRule(): void
    {
        $when = new When(
            static fn(mixed $value): bool => \is_string($value) && \str_starts_with($value, 'PROMO-'),
            [new MinLength(12)],
        );
        $rules = $this->translator->translate($when);
        self::assertInstanceOf(WhenRule::class, $rules[0]);

        self::assertTrue($this->fails($rules, 'PROMO-X'));   // condition holds → length checked
        self::assertFalse($this->fails($rules, 'FREE'));     // condition fails → skipped
    }

    #[Test]
    public function itShipsTheClosureDateBoundsAsInvokableRules(): void
    {
        $after = $this->translator->translate(new After(new \DateTimeImmutable('2020-01-01T00:00:00+00:00')));
        $before = $this->translator->translate(new Before(new \DateTimeImmutable('2020-01-01T00:00:00+00:00')));
        $between = $this->translator->translate(new Between(
            new \DateTimeImmutable('2020-01-01T00:00:00+00:00'),
            new \DateTimeImmutable('2020-12-31T00:00:00+00:00'),
        ));

        self::assertInstanceOf(AfterDate::class, $after[0]);
        self::assertInstanceOf(BeforeDate::class, $before[0]);
        self::assertInstanceOf(BetweenDates::class, $between[0]);

        self::assertTrue($this->fails($after, '2019-01-01T00:00:00+00:00'));
        self::assertFalse($this->fails($after, '2021-01-01T00:00:00+00:00'));
        self::assertTrue($this->fails($before, '2021-01-01T00:00:00+00:00'));
        self::assertTrue($this->fails($between, '2021-06-01T00:00:00+00:00'));
        self::assertFalse($this->fails($between, '2020-06-01T00:00:00+00:00'));
    }

    #[Test]
    public function itDelegatesAnUnknownConstraintToARegisteredExtensionTranslator(): void
    {
        $custom = new class implements ConstraintInterface {
            public function context(): \haddowg\JsonApi\Resource\Constraint\Context
            {
                return new \haddowg\JsonApi\Resource\Constraint\Context();
            }
        };

        $extension = new class implements ConstraintTranslatorInterface {
            public function supports(ConstraintInterface $constraint): bool
            {
                return true;
            }

            public function translate(ConstraintInterface $constraint): array
            {
                return ['in:red,green'];
            }
        };

        $translator = new ConstraintTranslator([$extension]);
        self::assertSame(['in:red,green'], $translator->translate($custom));
    }

    #[Test]
    public function itFailsLoudForAnUntranslatableConstraint(): void
    {
        $custom = new class implements ConstraintInterface {
            public function context(): \haddowg\JsonApi\Resource\Constraint\Context
            {
                return new \haddowg\JsonApi\Resource\Constraint\Context();
            }
        };

        $this->expectException(\LogicException::class);
        $this->translator->translate($custom);
    }

    /**
     * Whether validating `$value` against the translated `$rules` fails, with a non-empty
     * message on failure (proving the rule has teeth and carries a detail).
     *
     * @param list<mixed> $rules
     */
    private function fails(array $rules, mixed $value): bool
    {
        $app = $this->app;
        \assert($app !== null);
        /** @var ValidationFactory $factory */
        $factory = $app->make(ValidationFactory::class);
        $validator = $factory->make(['value' => $value], ['value' => $rules]);

        if (!$validator->fails()) {
            return false;
        }

        self::assertNotSame('', $validator->errors()->first('value'), 'a failing rule carries a message');

        return true;
    }
}
