<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The schema behind the write-transaction conformance suite: widgets with unique names,
 * the notes a listener writes on the side, and the join table between them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_widgets', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
        });

        Schema::create('tx_notes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('body');
        });

        Schema::create('tx_widget_note', function (Blueprint $table): void {
            $table->unsignedInteger('tx_widget_id');
            $table->unsignedInteger('tx_note_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tx_widget_note');
        Schema::dropIfExists('tx_notes');
        Schema::dropIfExists('tx_widgets');
    }
};
