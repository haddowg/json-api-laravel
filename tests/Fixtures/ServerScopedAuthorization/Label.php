<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */
final class Label extends Model
{
    protected $table = 'ssa_labels';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
