<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ThrCampaign extends Model
{
    protected $table = 'thr_campaigns';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'budget' => 'integer',
        'start_date' => 'date',
        'archived_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<ThrAdvertiser, $this>
     */
    public function advertiser(): BelongsTo
    {
        return $this->belongsTo(ThrAdvertiser::class, 'advertiser_id');
    }

    /**
     * @return HasMany<ThrLead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(ThrLead::class, 'campaign_id');
    }
}
