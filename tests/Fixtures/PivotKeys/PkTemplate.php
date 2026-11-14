<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A campaign template keyed by its campaign: the primary key is `campaign_id`, which is not
 * incrementing.
 *
 * @property int    $campaign_id
 * @property string $name
 */
final class PkTemplate extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected $table = 'pk_templates';

    protected $primaryKey = 'campaign_id';

    /**
     * @return BelongsToMany<PkDefinition, $this, PkTemplatePlaceholder>
     */
    public function placeholders(): BelongsToMany
    {
        return $this->belongsToMany(PkDefinition::class, 'pk_template_placeholders', 'template_id', 'definition_id', 'campaign_id', 'id')
            ->using(PkTemplatePlaceholder::class)
            ->withPivot('id', 'default_override');
    }

    /**
     * @return HasMany<PkTemplatePlaceholder, $this>
     */
    public function placeholderRows(): HasMany
    {
        return $this->hasMany(PkTemplatePlaceholder::class, 'template_id', 'campaign_id');
    }
}
