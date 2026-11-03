<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The `sortCampaigns` Eloquent model for the sort-family cases. Its placeholder values
 * live in `sort_campaign_values`, which the `value.<name>` sort's arm joins.
 *
 * @property int $id
 */
final class SortCampaign extends Model
{
    protected $table = 'sort_campaigns';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<SortCampaignValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(SortCampaignValue::class);
    }

    public function placeholderValue(string $placeholder): ?float
    {
        $value = $this->values->firstWhere('name', $placeholder)?->value;

        return \is_numeric($value) ? (float) $value : null;
    }
}
