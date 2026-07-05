<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The model behind the `pressings` fixture type — named exactly what the convention
 * tier guesses (`pressings` → `Pressing` under the configured
 * `jsonapi.eloquent.model_namespace`), so the type is served with no attribute and no
 * wiring at all (ADR 0019).
 *
 * @property int    $id
 * @property string $title
 *
 * @internal
 */
final class Pressing extends Model
{
    protected $table = 'pressings';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
