<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $label
 * @property bool   $hidden
 */
final class Mark extends Model
{
    protected $table = 'si_marks';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected $casts = ['hidden' => 'boolean'];
}
