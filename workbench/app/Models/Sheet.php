<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The `sheets` Eloquent model backing the open-object conformance suite: the row list
 * and each object attribute is a single `json` column with an `array` cast, the
 * undeclared members stored beside the declared children.
 *
 * @property int                       $id
 * @property string                    $name
 * @property list<mixed>|null          $rows
 * @property array<string, mixed>|null $labels
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $metrics
 */
final class Sheet extends Model
{
    protected $table = 'sheets';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'rows' => 'array',
        'labels' => 'array',
        'settings' => 'array',
        'metrics' => 'array',
    ];
}
