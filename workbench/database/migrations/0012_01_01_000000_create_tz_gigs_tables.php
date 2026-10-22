<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The output-timezone conformance tables: a gig with an instant, a calendar date and a
 * time of day, its venue, and a performer joined through a pivot with its own instant.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tz_venues', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->dateTime('opened_at');
        });

        Schema::create('tz_performers', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('tz_gigs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->dateTime('starts_at');
            $table->date('day');
            $table->time('doors_open');
            $table->unsignedInteger('venue_id');
        });

        Schema::create('tz_bookings', function (Blueprint $table): void {
            $table->unsignedInteger('gig_id');
            $table->unsignedInteger('performer_id');
            $table->dateTime('booked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tz_bookings');
        Schema::dropIfExists('tz_gigs');
        Schema::dropIfExists('tz_performers');
        Schema::dropIfExists('tz_venues');
    }
};
