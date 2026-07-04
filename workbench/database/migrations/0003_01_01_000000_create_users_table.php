<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `users` table backing the authorization showcase (PLAN decision 7). The
 * `is_admin`/`can_write` capability flags decide the policy outcomes; the security
 * suites drive users through `actingAs()`, so the table exists for realism +
 * `testbench serve` rather than as a hard test dependency.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->boolean('is_admin')->default(false);
            $table->boolean('can_write')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
