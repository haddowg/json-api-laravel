<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The playlist ⇄ track schema backing the Phase-3b pivot + relationship-mutation surface
 * on the Eloquent workbench provider. Two SEPARATE join tables keep the pivot-bearing and
 * the bare-join relations independent — as the in-memory witness's two independent member
 * lists (`$orderedTracks`/`$tracks`) are, so a mutation of one never changes the other on
 * EITHER provider (bundle parity — the Symfony example uses a separate bare join beside the
 * PlaylistEntry pivot):
 *  - `playlist_track` carries the pivot columns (`position`/`weight`/`added_at`); the
 *    `orderedTracks` relation reads/writes them (`withPivot`). `position` is NOT NULL so a
 *    genuinely-new membership missing it is caught as a `422` by the merge-before-validate
 *    pass BEFORE it reaches the DB (never a NOT-NULL `500`); `weight`/`added_at` are nullable
 *    (a server-owned `added_at`, an optional `weight`).
 *  - `playlist_track_plain` is the BARE join (no pivot columns) the plain `tracks` /
 *    `lockedTracks` relations resolve off. A `PATCH /playlists/{id}/relationships/tracks`
 *    id-only `sync()` inserts here with no `position` column to satisfy — no NOT-NULL 500, and
 *    the pivot membership is untouched (a `tracks` mutation on Eloquent no longer bleeds into
 *    `orderedTracks`, matching the witness).
 *
 * Both joins carry a composite unique key (a double-attach race is a constraint violation, not
 * a duplicate member) and foreign keys to `playlists`/`tracks`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->boolean('public')->default(true);
        });

        Schema::create('tracks', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->dateTime('released_at')->nullable();
        });

        Schema::create('playlist_track', function (Blueprint $table): void {
            $table->unsignedInteger('playlist_id');
            $table->unsignedInteger('track_id');
            $table->integer('position');
            $table->integer('weight')->nullable();
            $table->dateTime('added_at')->nullable();
            $table->unique(['playlist_id', 'track_id']);
            $table->foreign('playlist_id')->references('id')->on('playlists')->cascadeOnDelete();
            $table->foreign('track_id')->references('id')->on('tracks')->cascadeOnDelete();
        });

        Schema::create('playlist_track_plain', function (Blueprint $table): void {
            $table->unsignedInteger('playlist_id');
            $table->unsignedInteger('track_id');
            $table->unique(['playlist_id', 'track_id']);
            $table->foreign('playlist_id')->references('id')->on('playlists')->cascadeOnDelete();
            $table->foreign('track_id')->references('id')->on('tracks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_track_plain');
        Schema::dropIfExists('playlist_track');
        Schema::dropIfExists('tracks');
        Schema::dropIfExists('playlists');
    }
};
