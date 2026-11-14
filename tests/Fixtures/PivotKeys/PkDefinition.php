<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A placeholder definition keyed by a 16-byte binary UUID its constructor mints.
 *
 * @property string $id
 * @property string $name
 */
final class PkDefinition extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected $keyType = 'string';

    protected $table = 'pk_definitions';

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->attributes['id'] ??= Str::orderedUuid()->getBytes();
    }

    /**
     * @return BelongsToMany<PkTemplate, $this, PkTemplatePlaceholder>
     */
    public function templates(): BelongsToMany
    {
        return $this->belongsToMany(PkTemplate::class, 'pk_template_placeholders', 'definition_id', 'template_id', 'id', 'campaign_id')
            ->using(PkTemplatePlaceholder::class)
            ->withPivot('id', 'default_override');
    }

    /**
     * @return HasMany<PkTemplatePlaceholder, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(PkTemplatePlaceholder::class, 'definition_id');
    }
}
