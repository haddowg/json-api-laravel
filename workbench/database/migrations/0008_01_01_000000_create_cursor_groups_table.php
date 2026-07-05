<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `cursor_groups` parent table (plus the `group_id` FK on `cursor_widgets`) backing
 * the RELATED-collection cursor (keyset) conformance suite: `cursorGroups.widgets` is a
 * plain HasMany, so the parent-scoped keyset push-down composes on top of the FK
 * constraint. `group_id` is nullable — the primary cursor suite seeds widgets with no
 * groups at all.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cursor_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::table('cursor_widgets', function (Blueprint $table): void {
            $table->unsignedInteger('group_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cursor_widgets', function (Blueprint $table): void {
            $table->dropColumn('group_id');
        });
        Schema::dropIfExists('cursor_groups');
    }
};
