<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shelves and books behind the relation read order cases.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ro_shelves', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('ro_books', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('shelf_id')->nullable();
            $table->string('title');
            $table->unsignedInteger('year');
            $table->string('genre');
        });

        Schema::create('ro_shelf_favourite', function (Blueprint $table): void {
            $table->unsignedInteger('shelf_id');
            $table->unsignedInteger('book_id');
            $table->unique(['shelf_id', 'book_id']);
        });

        Schema::create('ro_shelf_pick', function (Blueprint $table): void {
            $table->unsignedInteger('shelf_id');
            $table->unsignedInteger('book_id');
            $table->unsignedInteger('rank');
            $table->unique(['shelf_id', 'book_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ro_shelf_pick');
        Schema::dropIfExists('ro_shelf_favourite');
        Schema::dropIfExists('ro_books');
        Schema::dropIfExists('ro_shelves');
    }
};
