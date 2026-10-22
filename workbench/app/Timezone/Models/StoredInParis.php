<?php

declare(strict_types=1);

namespace Workbench\App\Timezone\Models;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Timezone\TimezoneFixtures;

/**
 * Reads a column into a Europe/Paris `\DateTimeImmutable`, the zone the in-memory arm's
 * POPOs carry. An instant is stored in UTC; a `date` or `time` column is stored as the
 * local wall clock it names.
 *
 * @implements CastsAttributes<\DateTimeImmutable, \DateTimeInterface>
 */
final class StoredInParis implements CastsAttributes
{
    public function __construct(private readonly string $kind = 'datetime') {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }

        return match ($this->kind) {
            'date' => TimezoneFixtures::date($value),
            'time' => TimezoneFixtures::time($value),
            default => TimezoneFixtures::instant($value),
        };
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (!$value instanceof \DateTimeInterface) {
            return null;
        }

        return match ($this->kind) {
            'date' => $value->format('Y-m-d'),
            'time' => $value->format('H:i:s'),
            default => \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        };
    }
}
