<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Validation\Rules;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApiLaravel\Validation\Rules\AtLeastOneOf;
use haddowg\JsonApiLaravel\Validation\Rules\WhenRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pins the fix for the {@see WhenRule}/{@see AtLeastOneOf} dotted-key bug: both rules
 * re-enter the validator to run their inner/alternative rules, and previously keyed that
 * inner pass by the OUTER attribute name. For a {@see \haddowg\JsonApi\Resource\Field\Map}
 * child that name is dotted (`address.postcode`), which Laravel reads as a nested path into
 * the flat data array — so the value was never found and the inner rules silently never
 * ran. Both now validate under a fixed non-dotted key, so a `when()`/`AtLeastOneOf`
 * constraint declared on a Map child enforces its rules regardless of the attribute name.
 *
 * @internal
 */
#[CoversClass(WhenRule::class)]
#[CoversClass(AtLeastOneOf::class)]
final class DottedAttributeRulesTest extends Orchestra
{
    #[Test]
    public function when_rule_enforces_its_inner_rules_under_a_dotted_map_child_attribute(): void
    {
        $rule = new WhenRule(
            static fn(mixed $value, ?JsonApiRequestInterface $request): bool => true,
            ['string', 'min:5'],
        );

        // 'x' is shorter than the inner min:5, so the rule must report a violation even
        // though the attribute name is dotted (the pre-fix bug silently passed).
        self::assertNotSame([], $this->collect($rule, 'address.postcode', 'x'));
    }

    #[Test]
    public function when_rule_passes_a_satisfying_value_under_a_dotted_attribute(): void
    {
        $rule = new WhenRule(
            static fn(mixed $value, ?JsonApiRequestInterface $request): bool => true,
            ['string', 'min:5'],
        );

        self::assertSame([], $this->collect($rule, 'address.postcode', 'long-enough'));
    }

    #[Test]
    public function at_least_one_of_fails_all_alternatives_under_a_dotted_attribute(): void
    {
        // 'x' is neither an email nor >= 5 chars, so every alternative fails — a violation.
        $rule = new AtLeastOneOf([['email'], ['string', 'min:5']]);

        self::assertNotSame([], $this->collect($rule, 'address.postcode', 'x'));
    }

    #[Test]
    public function at_least_one_of_passes_when_an_alternative_matches_under_a_dotted_attribute(): void
    {
        $rule = new AtLeastOneOf([['email'], ['string', 'min:5']]);

        self::assertSame([], $this->collect($rule, 'address.postcode', 'long-enough'));
    }

    /**
     * @return list<string>
     */
    private function collect(ValidationRule $rule, string $attribute, mixed $value): array
    {
        $translator = app(\Illuminate\Contracts\Translation\Translator::class);

        $messages = [];
        $fail = static function (string $message) use (&$messages, $translator): PotentiallyTranslatedString {
            $messages[] = $message;

            return new PotentiallyTranslatedString($message, $translator);
        };

        $rule->validate($attribute, $value, $fail);

        return $messages;
    }
}
