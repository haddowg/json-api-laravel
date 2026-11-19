<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\WrittenNumbers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires `numberCards` over an in-memory store, with an action taking one as its input.
 *
 * @internal
 */
final class WrittenNumbersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([NumberCardResource::class, ScoreNumberCard::class]);

        $provider = new InMemoryDataProvider(
            'numberCards',
            [],
            identify: static fn(object $card): string => \is_string($id = Accessor::get($card, 'id')) ? $id : '',
            assignId: static function (object $card, string $id): void {
                Accessor::set($card, 'id', $id);
            },
        );
        JsonApi::provider($provider);
        JsonApi::persister(new InMemoryDataPersister('numberCards', $provider->store(), static fn(): NumberCard => new NumberCard()));
    }
}
