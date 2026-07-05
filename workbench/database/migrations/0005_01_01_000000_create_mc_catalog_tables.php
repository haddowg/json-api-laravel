<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The full music-catalog schema (decision 14) backing the unified 12-type domain under
 * {@see \Workbench\App\MusicCatalog}. Every table is `mc_`-prefixed so it never collides
 * with the per-phase suites' own `albums`/`artists`/… tables (which the same
 * `loadMigrationsFrom(workbench/database/migrations)` also creates for the existing
 * Eloquent suites); the resource `$type` strings stay the parity wire names, only the
 * storage tables are isolated (the Eloquent models set `$table = 'mc_…'`).
 *
 * Columns are snake_case, matching the resource field `storedAs()`/`Accessor` map and the
 * shared {@see \Workbench\App\MusicCatalog\Support\Fixtures}, so ONE resource declaration
 * serves both the Eloquent models and the in-memory POPOs.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mc_artists', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('slug');
            $table->string('website')->nullable();
            $table->text('bio')->nullable();
            $table->integer('track_count')->default(0);
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('mc_albums', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('artist_id')->nullable();
            $table->string('title');
            $table->decimal('average_rating', 4, 2)->nullable();
            $table->string('artwork')->nullable();
            $table->dateTime('released_at');
            $table->boolean('explicit')->default(false);
            $table->string('status')->default('released');
            $table->date('available_from')->nullable();
            $table->date('available_until')->nullable();
            $table->json('release_info')->nullable();
        });

        Schema::create('mc_tracks', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('album_id')->nullable();
            $table->string('title');
            $table->integer('track_number')->default(1);
            $table->integer('length_seconds')->default(0);
            $table->boolean('explicit')->default(false);
            $table->json('genres')->nullable();
            $table->string('preview_offset')->nullable();
        });

        Schema::create('mc_genres', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
        });

        Schema::create('mc_devices', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('label');
        });

        Schema::create('mc_products', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->string('name');
        });

        Schema::create('mc_users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('display_name');
            $table->date('birth_date')->nullable();
            $table->json('preferences')->nullable();
            $table->string('last_seen_ip')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_admin')->default(false);
        });

        Schema::create('mc_libraries', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('owner_id')->nullable();
        });

        Schema::create('mc_playlists', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedInteger('owner_id')->nullable();
            $table->string('title');
            $table->string('slug')->default('');
            $table->boolean('public')->default(true);
            $table->string('external_id')->nullable();
        });

        Schema::create('mc_favorites', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->dateTime('favorited_at')->nullable();
            $table->string('favoritable_type')->nullable();
            $table->string('favoritable_id')->nullable();
        });

        // The orderedTracks pivot (position/weight/added_at) — the pivot-bearing join.
        Schema::create('mc_playlist_track', function (Blueprint $table): void {
            $table->string('playlist_id');
            $table->unsignedInteger('track_id');
            $table->integer('position');
            $table->integer('weight')->nullable();
            $table->dateTime('added_at')->nullable();
            $table->unique(['playlist_id', 'track_id']);
        });

        // The bare join backing the plain playlists.tracks / tracks.playlists relation.
        Schema::create('mc_playlist_track_plain', function (Blueprint $table): void {
            $table->string('playlist_id');
            $table->unsignedInteger('track_id');
            $table->unique(['playlist_id', 'track_id']);
        });

        // The polymorphic pivot backing libraries.items (morphedByMany) — a mixed set of
        // tracks/albums/artists keyed by the morph alias in `item_type`.
        Schema::create('mc_library_items', function (Blueprint $table): void {
            $table->unsignedInteger('library_id');
            $table->unsignedInteger('item_id');
            $table->string('item_type');
            $table->unique(['library_id', 'item_id', 'item_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mc_library_items');
        Schema::dropIfExists('mc_playlist_track_plain');
        Schema::dropIfExists('mc_playlist_track');
        Schema::dropIfExists('mc_favorites');
        Schema::dropIfExists('mc_playlists');
        Schema::dropIfExists('mc_libraries');
        Schema::dropIfExists('mc_users');
        Schema::dropIfExists('mc_products');
        Schema::dropIfExists('mc_devices');
        Schema::dropIfExists('mc_genres');
        Schema::dropIfExists('mc_tracks');
        Schema::dropIfExists('mc_albums');
        Schema::dropIfExists('mc_artists');
    }
};
