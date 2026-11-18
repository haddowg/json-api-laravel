<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The schema behind the foreign-key conformance suite: the rows a provider-served user and a
 * team with no inverse relation reach through each relation's foreign key. A note's owner
 * can't be cleared.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fk_items', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('owner_key')->nullable();
        });

        Schema::create('fk_notes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('body');
            $table->string('owner');
            $table->boolean('archived')->default(false);
        });

        Schema::create('fk_profiles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('bio');
            $table->string('owner')->nullable();
        });

        Schema::create('fk_secrets', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('label');
            $table->string('owner')->nullable();
            $table->boolean('hidden')->default(false);
        });

        Schema::create('fk_teams', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('fk_members', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('team_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fk_members');
        Schema::dropIfExists('fk_teams');
        Schema::dropIfExists('fk_secrets');
        Schema::dropIfExists('fk_profiles');
        Schema::dropIfExists('fk_notes');
        Schema::dropIfExists('fk_items');
    }
};
