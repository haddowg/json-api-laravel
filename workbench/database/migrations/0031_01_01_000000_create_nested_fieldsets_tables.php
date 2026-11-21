<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `fs_panels` and `fs_curators` schema backing the Eloquent nested fieldsets
 * conformance suite: each object attribute is one nullable `json` column.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fs_curators', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->json('stats')->nullable();
            $table->json('office')->nullable();
            $table->json('exhibits')->nullable();
        });

        Schema::create('fs_panels', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->json('values')->nullable();
            $table->json('groups')->nullable();
            $table->json('settings')->nullable();
            $table->json('address')->nullable();
            $table->json('rows')->nullable();
            $table->json('archive')->nullable();
            $table->json('tags')->nullable();
            $table->json('blocks')->nullable();
            $table->json('hero')->nullable();
            $table->unsignedInteger('curator_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fs_panels');
        Schema::dropIfExists('fs_curators');
    }
};
