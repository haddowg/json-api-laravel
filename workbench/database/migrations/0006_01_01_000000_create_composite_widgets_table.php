<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `composite_widgets` schema backing the Eloquent composite-attribute conformance
 * suite: each composite attribute (Obj `address`, OneOf `block`, ArrayHash+Shape
 * `contact`) is a single nullable `json` column — the shape the
 * {@see \Workbench\App\Validation\CompositeWidgetResource} round-trips as one value.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('composite_widgets', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->json('address')->nullable();
            $table->json('block')->nullable();
            $table->json('contact')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composite_widgets');
    }
};
