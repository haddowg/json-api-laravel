<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The music catalog's plays: a track played on a station. `station` holds the id of a station
 * no table stores, which the station's `plays` reads through as its foreign key.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mc_plays', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('station', 32);
            $table->dateTime('played_at');
            $table->unsignedInteger('track_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mc_plays');
    }
};
