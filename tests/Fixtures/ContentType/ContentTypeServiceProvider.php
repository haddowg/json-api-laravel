<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ContentType;

use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * @internal
 */
final class ContentTypeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ImportAlbumsAction::class, UploadArtworkAction::class, PingAlbumsAction::class, ReindexAlbumsAction::class]);
    }
}
