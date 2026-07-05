<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `cursor_boards` parent table plus the `cursor_board_widget` pivot backing the
 * PIVOT-bearing related-collection cursor (keyset) conformance suite:
 * `cursorBoards.widgets` is a belongsToMany carrying a `position` pivot column, so the
 * parent-scoped keyset push-down composes on top of the pivot INNER JOIN while the
 * handler's `meta.pivot` wrap reads the stored position per member. The pivot `id`
 * column deliberately collides with the related table's PK — the qualified
 * `related.*` projection (and the model-table-qualified keyset ORDER BY/WHERE) must
 * keep the widget's own id on the wire.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cursor_boards', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('cursor_board_widget', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('board_id');
            $table->unsignedInteger('widget_id');
            $table->integer('position')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cursor_board_widget');
        Schema::dropIfExists('cursor_boards');
    }
};
