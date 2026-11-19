<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class ThrLead extends Model
{
    protected $table = 'thr_leads';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<ThrCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ThrCampaign::class, 'campaign_id');
    }

    /**
     * @return BelongsToMany<ThrTemplate, $this>
     */
    public function templates(): BelongsToMany
    {
        return $this->belongsToMany(ThrTemplate::class, 'thr_lead_template', 'lead_id', 'template_id');
    }
}
