<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Str;

/**
 * The pivot between a template and a definition, with a 16-byte binary UUID id its
 * constructor mints, so a row built without the constructor has none.
 *
 * @property string      $id
 * @property int         $template_id
 * @property string      $definition_id
 * @property string|null $default_override
 */
final class PkTemplatePlaceholder extends Pivot
{
    public $timestamps = false;

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected $keyType = 'string';

    protected $table = 'pk_template_placeholders';

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->attributes['id'] ??= Str::orderedUuid()->getBytes();
    }

    /**
     * @return BelongsTo<PkTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(PkTemplate::class, 'template_id', 'campaign_id');
    }

    /**
     * @return BelongsTo<PkDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(PkDefinition::class, 'definition_id');
    }

}
