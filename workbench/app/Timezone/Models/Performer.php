<?php

declare(strict_types=1);

namespace Workbench\App\Timezone\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */
final class Performer extends Model
{
    protected $table = 'tz_performers';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
