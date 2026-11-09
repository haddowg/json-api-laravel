<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The squads and players behind the relation-bounds cases, joined through `rb_squad_player`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('rb_players', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('rb_squads', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('rb_squad_player', function (Blueprint $table): void {
            $table->unsignedInteger('squad_id');
            $table->unsignedInteger('player_id');
            $table->unique(['squad_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rb_squad_player');
        Schema::dropIfExists('rb_squads');
        Schema::dropIfExists('rb_players');
    }
};
