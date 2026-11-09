<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `sheets` schema backing the Eloquent open-object conformance suite: the row list
 * and each object attribute is a single nullable `json` column.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('sheets', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->json('rows')->nullable();
            $table->json('labels')->nullable();
            $table->json('settings')->nullable();
            $table->json('metrics')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheets');
    }
};
