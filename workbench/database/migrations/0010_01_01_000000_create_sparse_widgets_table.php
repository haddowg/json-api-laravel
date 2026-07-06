<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `sparse_widgets` schema backing the Eloquent sparse-by-default conformance suite:
 * a cheap `name` column plus an `expensive_score` column the
 * {@see \Workbench\App\Sparse\SparseWidgetResource} exposes as the sparse-by-default
 * `expensiveScore` attribute — omitted by default, rendered only when named in
 * `fields[sparseWidgets]`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('sparse_widgets', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->integer('expensive_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sparse_widgets');
    }
};
