<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\GettingStarted\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The getting-started `Album` model — deliberately verbatim from
 * [docs/getting-started.md] step 1: a plain Eloquent model, nothing JSON:API-specific,
 * no `$table`, no wiring. The convention tier (ADR 0019) maps the `albums` type to it
 * purely by name under the test's configured `jsonapi.eloquent.model_namespace`.
 *
 * @property int    $id
 * @property string $title
 *
 * @internal
 */
final class Album extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = ['released_at' => 'datetime', 'explicit' => 'boolean'];
}
