<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `presets` schema backing the Eloquent union conformance suite: each union a
 * nullable `json` column, so a value keeps its JSON type.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('presets', function (Blueprint $table): void {
            $table->increments('id');
            $table->json('value')->nullable();
            $table->json('label')->nullable();
            $table->json('mode')->nullable();
            $table->json('due')->nullable();
            $table->json('at')->nullable();
            $table->json('extra')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presets');
    }
};
