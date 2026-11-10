<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The queues behind the positioned relation cases. Every position is unique per queue, so a
 * write that moves members in place, rather than in two passes, fails.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pq_queues', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('pq_songs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('queue_id')->nullable();
            $table->unsignedInteger('slot')->nullable();
            $table->unique(['queue_id', 'slot']);
        });

        Schema::create('pq_favourites', function (Blueprint $table): void {
            $table->unsignedInteger('queue_id');
            $table->unsignedInteger('song_id');
            $table->unsignedInteger('position');
            $table->unique(['queue_id', 'song_id']);
            $table->unique(['queue_id', 'position']);
        });

        Schema::create('pq_mix_rows', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('queue_id');
            $table->string('item_type');
            $table->unsignedInteger('song_id');
            $table->unsignedInteger('position');
            $table->unique(['queue_id', 'position']);
        });

        // An identifiedBy() closure's fillUsing() hook creates rows before they are placed.
        Schema::create('pq_loose_mix_rows', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('queue_id');
            $table->unsignedInteger('song_id');
            $table->unsignedInteger('position')->nullable();
            $table->unique(['queue_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pq_loose_mix_rows');
        Schema::dropIfExists('pq_mix_rows');
        Schema::dropIfExists('pq_favourites');
        Schema::dropIfExists('pq_songs');
        Schema::dropIfExists('pq_queues');
    }
};
