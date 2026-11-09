<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `submissions` schema backing the Eloquent JSON type conformance suite: a column of
 * each scalar kind, and each object attribute and list a single nullable `json` column.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->integer('count')->nullable();
            $table->float('ratio')->nullable();
            $table->boolean('flag')->nullable();
            $table->date('day')->nullable();
            $table->dateTime('at')->nullable();
            $table->string('kind')->nullable();
            $table->json('tags')->nullable();
            $table->json('options')->nullable();
            $table->json('rows')->nullable();
            $table->json('mapping')->nullable();
            $table->json('block')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
