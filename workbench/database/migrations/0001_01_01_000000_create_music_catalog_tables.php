<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The music-catalog schema backing the Eloquent workbench provider. Columns are
 * snake_case, matching the resource field `storedAs()` map and the shared
 * {@see \Workbench\App\Support\Fixtures} keys, so the SAME resource declaration serves
 * both the in-memory POPOs and these tables.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('artists', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('slug');
            $table->string('website')->nullable();
            $table->text('bio')->nullable();
            $table->integer('track_count')->default(0);
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('albums', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('artist_id')->nullable();
            $table->string('title');
            $table->decimal('average_rating', 4, 2)->nullable();
            $table->string('status');
            $table->boolean('explicit')->default(false);
            $table->date('available_from')->nullable();
            // NOT NULL: the DateRange filter (releasedRange) and the default sort both
            // read released_at, so it is kept non-null — ordered comparison filters are
            // declared only over columns with no null rows (witness-vs-SQL null parity).
            $table->dateTime('released_at');
        });

        Schema::create('genres', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
        });

        // Backs the cursor (keyset) conformance suite: a nullable int (`priority`) and a
        // nullable datetime (`released_at`) exercise the forced NULL=largest ordering,
        // and a ties-carrying `category` exercises the appended PK tiebreak.
        Schema::create('cursor_widgets', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('category');
            $table->integer('priority')->nullable();
            $table->dateTime('released_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cursor_widgets');
        Schema::dropIfExists('albums');
        Schema::dropIfExists('artists');
        Schema::dropIfExists('genres');
    }
};
