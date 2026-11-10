<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CountThrough;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int      $id
 * @property string   $name
 * @property int      $campaign_id
 * @property int|null $brand_id
 */
final class Lead extends Model
{
    protected $table = 'ct_leads';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsToMany<Template, $this>
     */
    public function templates(): BelongsToMany
    {
        return $this->belongsToMany(Template::class, 'ct_lead_template', 'lead_id', 'template_id');
    }
}
