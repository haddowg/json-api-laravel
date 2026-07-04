<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `articles` schema backing the Eloquent validation-conformance suite and the
 * `Rule::unique` (UniqueEntity) witness. Columns are snake_case, matching the
 * {@see \Workbench\App\Validation\ArticleResource} field `storedAs()` map and the shared
 * {@see \Workbench\App\Validation\Article} POPO, so one resource declaration drives both
 * providers. `slug` carries no DB unique index on purpose — uniqueness is enforced by
 * the bridge's pre-hydration `Rule::unique`, which is what the suite refereeing.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('body')->nullable();
            $table->string('category')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('coupon_code')->nullable();
            $table->json('address')->nullable();
            $table->string('slug')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
