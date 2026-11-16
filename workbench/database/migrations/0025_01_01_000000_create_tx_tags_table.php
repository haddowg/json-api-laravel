<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The write-transaction suite's tags, whose ids the client names.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_tags', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tx_tags');
    }
};
