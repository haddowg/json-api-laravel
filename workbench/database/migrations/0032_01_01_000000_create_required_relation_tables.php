<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tasks and members behind the required-relation cases. The owner column takes
 * `null`, so only validation refuses a task without one.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('rr_members', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('rr_tasks', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('owner_id')->nullable();
        });

        Schema::create('rr_task_watcher', function (Blueprint $table): void {
            $table->unsignedInteger('task_id');
            $table->unsignedInteger('member_id');
            $table->unique(['task_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rr_task_watcher');
        Schema::dropIfExists('rr_tasks');
        Schema::dropIfExists('rr_members');
    }
};
