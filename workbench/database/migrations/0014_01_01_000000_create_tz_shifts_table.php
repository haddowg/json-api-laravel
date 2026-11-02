<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `tz_shifts` table behind the storage-timezone cases, a naive datetime and a date,
 * plus workers joined through a pivot with an uncast `checked_in_at`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tz_shifts', function (Blueprint $table): void {
            $table->increments('id');
            $table->dateTime('starts_at')->nullable();
            $table->date('day')->nullable();
        });

        Schema::create('tz_workers', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('tz_shift_worker', function (Blueprint $table): void {
            $table->unsignedInteger('shift_id');
            $table->unsignedInteger('worker_id');
            $table->dateTime('checked_in_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tz_shift_worker');
        Schema::dropIfExists('tz_workers');
        Schema::dropIfExists('tz_shifts');
    }
};
