<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CountThrough;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int      $id
 * @property string   $name
 * @property int|null $brand_id
 */
final class Campaign extends Model
{
    protected $table = 'ct_campaigns';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * @return BelongsToMany<Lead, $this>
     */
    public function shortlist(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'ct_campaign_shortlist', 'campaign_id', 'lead_id');
    }
}
