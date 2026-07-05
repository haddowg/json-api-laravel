<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `mc_releases` schema backing the music-catalog composite-attribute showcase:
 * each composite attribute (OneOf `format`, Obj `packaging`, ArrayHash+Shape
 * `availability`/`dimensions`) is a single nullable `json` column — one value in, one
 * value out (the {@see \Workbench\App\MusicCatalog\JsonApi\ReleaseResource} shape).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mc_releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('album_id')->nullable();
            $table->string('catalog_number', 40);
            $table->json('format')->nullable();
            $table->json('packaging')->nullable();
            $table->json('availability')->nullable();
            $table->json('dimensions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mc_releases');
    }
};
