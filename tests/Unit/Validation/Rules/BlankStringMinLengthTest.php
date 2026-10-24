<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Validation\Rules;

use haddowg\JsonApiLaravel\Validation\Rules\BlankStringMinLength;
use Illuminate\Support\Facades\Validator;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Laravel skips `string` and `min:N` for an empty or whitespace-only string, so the field
 * rule-set carries {@see BlankStringMinLength} to enforce a `minLength` on those values.
 *
 * @internal
 */
#[CoversClass(BlankStringMinLength::class)]
final class BlankStringMinLengthTest extends Orchestra
{
    #[Test]
    public function an_empty_string_fails_a_positive_min_length(): void
    {
        self::assertTrue($this->fails(1, ['slug' => '']));
    }

    #[Test]
    public function a_whitespace_string_is_measured_by_its_length(): void
    {
        self::assertFalse($this->fails(1, ['slug' => ' ']));
        self::assertTrue($this->fails(3, ['slug' => '  ']));
    }

    #[Test]
    public function an_absent_null_or_non_blank_value_is_left_to_the_other_rules(): void
    {
        self::assertFalse($this->fails(1, []));
        self::assertFalse($this->fails(1, ['slug' => 'radiohead']));
        self::assertTrue($this->fails(3, ['slug' => 'ab']));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fails(int $min, array $data): bool
    {
        return Validator::make($data, [
            'slug' => ['sometimes', 'string', 'min:' . $min, new BlankStringMinLength($min)],
        ])->fails();
    }
}
