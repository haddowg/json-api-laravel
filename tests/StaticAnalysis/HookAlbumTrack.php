<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\StaticAnalysis;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The join row of an album's tracks, which keeps the members an open pivot takes.
 *
 * @property array<string, mixed>|null $extras
 */
final class HookAlbumTrack extends Pivot
{
    protected $casts = ['extras' => 'array'];
}
