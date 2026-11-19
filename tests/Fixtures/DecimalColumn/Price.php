<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\DecimalColumn;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * `price` has Eloquent's `decimal:2` cast and `rate` a set mutator typed `string`, the
 * two ways a model keeps a decimal column as a string.
 *
 * @property int     $id
 * @property ?string $price
 * @property ?string $rate
 *
 * @internal
 */
final class Price extends Model
{
    /**
     * The raw `rate` attribute each save wrote, in order.
     *
     * @var list<mixed>
     */
    public static array $savedRates = [];

    public $timestamps = false;

    protected $table = 'dc_prices';

    protected $guarded = [];

    protected $casts = ['price' => 'decimal:2'];

    protected static function booted(): void
    {
        self::saving(static function (Price $price): void {
            self::$savedRates[] = $price->getAttributes()['rate'] ?? null;
        });
    }

    /**
     * @return Attribute<never, string>
     */
    protected function rate(): Attribute
    {
        return Attribute::make(set: static fn(string $value): string => $value);
    }
}
