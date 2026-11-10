<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use haddowg\JsonApiLaravel\Eloquent\AsJsonValue;
use Illuminate\Database\Eloquent\Model;

/**
 * The `presets` Eloquent model backing the union conformance suite. Each union column is
 * `json` with the {@see AsJsonValue} cast, holding the union's stored form.
 *
 * @property int                       $id
 * @property mixed                     $value
 * @property mixed                     $label
 * @property mixed                     $mode
 * @property mixed                     $due
 * @property mixed                     $at
 * @property array<string, mixed>|null $extra
 */
final class Preset extends Model
{
    protected $table = 'presets';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'value' => AsJsonValue::class,
        'label' => AsJsonValue::class,
        'mode' => AsJsonValue::class,
        'due' => AsJsonValue::class,
        'at' => AsJsonValue::class,
        'extra' => 'array',
    ];
}
