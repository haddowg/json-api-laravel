<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int    $id
 * @property string $status
 * @property int    $campaign_id
 */
final class Lead extends Model
{
    protected $table = 'tf_leads';

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
     * @return BelongsToMany<Template, $this>
     */
    public function templates(): BelongsToMany
    {
        return $this->belongsToMany(Template::class, 'tf_lead_template', 'lead_id', 'template_id');
    }
}
