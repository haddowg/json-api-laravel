<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The teams behind the cursor-sort cases, their members' scores, a division they belong
 * to, and a league that holds the teams through a pivot table.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('score_teams', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->integer('base')->nullable();
            $table->integer('bonus')->nullable();
            $table->unsignedInteger('score_division_id')->nullable();
        });

        Schema::create('score_divisions', function (Blueprint $table): void {
            $table->increments('id');
        });

        Schema::create('score_members', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('score_team_id');
            $table->integer('score');
        });

        Schema::create('score_leagues', function (Blueprint $table): void {
            $table->increments('id');
        });

        Schema::create('score_league_team', function (Blueprint $table): void {
            $table->unsignedInteger('score_league_id');
            $table->unsignedInteger('score_team_id');
            $table->integer('slot')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_league_team');
        Schema::dropIfExists('score_leagues');
        Schema::dropIfExists('score_members');
        Schema::dropIfExists('score_divisions');
        Schema::dropIfExists('score_teams');
    }
};
