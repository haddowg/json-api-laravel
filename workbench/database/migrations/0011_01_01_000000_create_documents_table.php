<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `documents` schema backing the first-class soft-delete showcase
 * ({@see \Workbench\App\SoftDelete\DocumentResource} + {@see \Workbench\App\Models\Document}).
 * The `deleted_at` tombstone (via `softDeletes()`) is what makes `DELETE` recoverable and the
 * `restore`/`force-delete` actions meaningful.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('body')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
