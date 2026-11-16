<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The schema behind the singleton conformance suite: each user's inbox, its items, and the
 * labels an inbox carries.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('inboxes', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('owner_id');
            $table->string('title');
        });

        Schema::create('inbox_items', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('inbox_id');
            $table->string('subject');
        });

        Schema::create('inbox_labels', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('inbox_label', function (Blueprint $table): void {
            $table->unsignedInteger('inbox_id');
            $table->unsignedInteger('inbox_label_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_label');
        Schema::dropIfExists('inbox_labels');
        Schema::dropIfExists('inbox_items');
        Schema::dropIfExists('inboxes');
    }
};
