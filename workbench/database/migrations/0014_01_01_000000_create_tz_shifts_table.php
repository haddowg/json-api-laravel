<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `tz_shifts` table behind the storage-timezone cases: a naive datetime and a date.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tz_shifts', function (Blueprint $table): void {
            $table->increments('id');
            $table->dateTime('starts_at')->nullable();
            $table->date('day')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tz_shifts');
    }
};
