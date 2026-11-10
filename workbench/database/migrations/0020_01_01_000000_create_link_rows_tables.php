<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The boards behind the link row cases: notes and photos linked through `lr_board_items`
 * rows, and notes pinned through the `lr_pinnables` morph pivot.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('lr_boards', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('lr_notes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
        });

        Schema::create('lr_photos', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('caption');
        });

        Schema::create('lr_board_items', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('board_id');
            $table->string('item_type');
            $table->unsignedInteger('item_id');
            $table->integer('position')->default(0);
            $table->string('label')->nullable();
        });

        Schema::create('lr_pinnables', function (Blueprint $table): void {
            $table->unsignedInteger('note_id');
            $table->string('pinnable_type');
            $table->unsignedInteger('pinnable_id');
            $table->integer('rank')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lr_pinnables');
        Schema::dropIfExists('lr_board_items');
        Schema::dropIfExists('lr_photos');
        Schema::dropIfExists('lr_notes');
        Schema::dropIfExists('lr_boards');
    }
};
