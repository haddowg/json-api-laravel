<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The `curators` Eloquent model of the nested fieldsets conformance suite, the same shape
 * as the in-memory {@see \Workbench\App\NestedFieldsets\Curator}.
 *
 * @property int                       $id
 * @property string                    $name
 * @property array<string, mixed>|null $stats
 * @property array<string, mixed>|null $office
 * @property list<mixed>|null          $exhibits
 */
final class Curator extends Model
{
    protected $table = 'fs_curators';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'stats' => 'array',
        'office' => 'array',
        'exhibits' => 'array',
    ];
}
