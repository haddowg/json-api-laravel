<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `checklists` table behind the required-field validation cases.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('checklists', function (Blueprint $table): void {
            $table->increments('id');
            $table->boolean('done');
            $table->integer('count');
            $table->float('ratio');
            $table->string('title');
            $table->json('tags');
            $table->json('settings');
            $table->string('note')->nullable();
            $table->json('options')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklists');
    }
};
