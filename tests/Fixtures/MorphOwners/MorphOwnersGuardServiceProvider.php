<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Registers `$subject` as the posts beside the notes and videos, with posts served from memory
 * when `$modelledPosts` is false.
 */
final class MorphOwnersGuardServiceProvider extends ServiceProvider
{
    /**
     * @var class-string
     */
    public static string $subject = MoBareIdPostResource::class;

    public static bool $modelledPosts = true;

    public function register(): void
    {
        JsonApi::register([self::$subject, MoVideoResource::class, MoNoteResource::class])->ignoreRoutes();
        $models = ['moVideos' => MoVideo::class, 'moNotes' => MoNote::class];
        if (self::$modelledPosts) {
            $models['moPosts'] = MoPost::class;
        } else {
            JsonApi::provider(new InMemoryDataProvider('moPosts', []));
        }
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
    }
}
