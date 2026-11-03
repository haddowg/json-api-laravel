<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One placeholder value of a {@see SortCampaign}, stored as text.
 *
 * @property int         $sort_campaign_id
 * @property string      $name
 * @property string|null $value
 */
final class SortCampaignValue extends Model
{
    protected $table = 'sort_campaign_values';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
