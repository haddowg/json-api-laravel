<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The model behind the `recordings` fixture type — its name deliberately diverges from
 * the type (convention would guess `Recording`, which does not exist), so the type is
 * only servable through the `#[AsJsonApiResource(model: VinylRecord::class)]`
 * declaration (the attribute tier, ADR 0019).
 *
 * @property int    $id
 * @property string $title
 *
 * @internal
 */
final class VinylRecord extends Model
{
    protected $table = 'vinyl_records';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
