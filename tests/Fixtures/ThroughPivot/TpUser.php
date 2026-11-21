<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A user who adds tracks to playlists, hidden from every query when flagged `hidden`.
 */
final class TpUser extends Model
{
    protected $table = 'tp_users';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope('visible', static function (Builder $query): void {
            $query->where($query->qualifyColumn('hidden'), false);
        });
    }
}
