<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `sort_campaigns` table behind the sort-family cases, and the placeholder values a
 * `value.<name>` sort reads, one row per campaign and placeholder stored as text.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('sort_campaigns', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('team');
        });

        Schema::create('sort_campaign_values', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('sort_campaign_id');
            $table->string('name');
            $table->text('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sort_campaign_values');
        Schema::dropIfExists('sort_campaigns');
    }
};
