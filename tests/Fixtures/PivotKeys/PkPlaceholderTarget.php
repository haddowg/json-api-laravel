<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Where a template's placeholder is rendered, linked to the pivot row rather than to the
 * definition. Not a resource: its binary UUID key is minted by its constructor and cast to
 * the canonical string.
 *
 * @property string $id
 * @property string $name
 */
final class PkPlaceholderTarget extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected $keyType = 'string';

    protected $table = 'pk_placeholder_targets';

    /**
     * @var array<string, string>
     */
    protected $casts = ['id' => BinaryUuid::class];

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->attributes['id'] ??= Str::orderedUuid()->getBytes();
    }
}
