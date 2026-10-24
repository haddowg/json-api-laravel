<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The Eloquent `checklists` model behind the validation-conformance suite's
 * {@see \Workbench\App\Validation\ChecklistResource}.
 */
final class Checklist extends Model
{
    protected $table = 'checklists';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'done' => 'boolean',
        'count' => 'integer',
        'ratio' => 'float',
        'tags' => 'array',
        'settings' => 'array',
        'options' => 'array',
    ];
}
