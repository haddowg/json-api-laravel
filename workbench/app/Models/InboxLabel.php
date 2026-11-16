<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A label an inbox carries.
 *
 * @property int    $id
 * @property string $name
 */
final class InboxLabel extends Model
{
    public $timestamps = false;

    protected $table = 'inbox_labels';

    protected $guarded = [];
}
