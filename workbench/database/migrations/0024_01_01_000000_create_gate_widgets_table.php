<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The schema behind the gate-order conformance suite: widgets with an owner, a partner and a
 * parent.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('gate_widgets', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('owner');
            $table->unsignedInteger('partner_id')->nullable();
            $table->unsignedInteger('parent_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_widgets');
    }
};
