<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The workbench `users` model — the authenticated principal the policies (PLAN
 * decision 7 showcase) authorize against. `is_admin` grants the policy `before()`
 * bypass; `can_write` grants the create/update/`publish` abilities; `can_read` gates the
 * `viewAny`/`view` read abilities (so the suite can assert a DENIED read/list on the
 * dedicated-policy path). Kept minimal (no password reset / notifications); the security
 * suites drive it through Laravel's native `actingAs()`, so an unsaved instance is enough
 * and no table read is required.
 *
 * @property int    $id
 * @property string $name
 * @property bool   $is_admin
 * @property bool   $can_write
 * @property bool   $can_read
 */
final class User extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_admin' => 'boolean',
        'can_write' => 'boolean',
        'can_read' => 'boolean',
    ];
}
