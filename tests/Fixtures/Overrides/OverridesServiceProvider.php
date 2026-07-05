<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Overrides;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the serializer/hydrator-override fixture (ADR 0014): binds the scalar
 * constructor arguments the two override classes require (contextual bindings — the
 * DI-resolution proof), registers {@see NoteResource} (serializer override) and
 * {@see MemoResource} (hydrator override) explicitly, and seeds each type's in-memory
 * provider + persister so reads and writes both run end-to-end. Everything runs in
 * `register()` so it lands before the package provider's `boot()` reads the discovery
 * + provider registrations.
 *
 * @internal
 */
final class OverridesServiceProvider extends ServiceProvider
{
    public const string STAMP = 'container-bound-catalogue';

    public const string SLUG_SEPARATOR = '_';

    public function register(): void
    {
        $this->app->when(NoteSerializer::class)->needs('$stamp')->give(self::STAMP);
        $this->app->when(MemoHydrator::class)->needs('$slugSeparator')->give(self::SLUG_SEPARATOR);

        JsonApi::register([NoteResource::class, MemoResource::class]);

        $identify = static fn(object $item): string => match (true) {
            $item instanceof Note, $item instanceof Memo => $item->id,
            default => '',
        };

        $noteProvider = new InMemoryDataProvider('notes', ['1' => new Note('1', 'First note')], identify: $identify);
        JsonApi::provider($noteProvider);
        JsonApi::persister(new InMemoryDataPersister('notes', $noteProvider->store(), static fn(): Note => new Note()));

        $memoProvider = new InMemoryDataProvider('memos', ['1' => new Memo('1', 'First memo', 'first_memo')], identify: $identify);
        JsonApi::provider($memoProvider);
        JsonApi::persister(new InMemoryDataPersister('memos', $memoProvider->store(), static fn(): Memo => new Memo()));
    }
}
