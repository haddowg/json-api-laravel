<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The typed-document-meta suite's boards and cards: a board holds cards and pins one.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tm_boards', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('pinned_id')->nullable();
        });

        Schema::create('tm_cards', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->boolean('done')->default(false);
            $table->unsignedInteger('board_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tm_cards');
        Schema::dropIfExists('tm_boards');
    }
};
