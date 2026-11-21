<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An advertiser, hidden from every query when it is flagged `hidden`.
 */
final class ThrAdvertiser extends Model
{
    protected $table = 'thr_advertisers';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope('visible', static function (Builder $query): void {
            $query->where($query->qualifyColumn('hidden'), false);
        });
    }

    /**
     * @return HasMany<ThrCampaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(ThrCampaign::class, 'advertiser_id');
    }
}
