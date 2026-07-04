<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\EntitySeam;

use haddowg\JsonApiLaravel\DataProvider\InMemoryStore;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The invokable rule the {@see UniqueNoteTitleTranslator} produces. The validated value is
 * the HYDRATED ENTITY object (the entity seam passes the entity as the rule value, not a
 * scalar attribute), so it scans the shared {@see InMemoryStore} for another note carrying
 * the same title — self-excluded by id, so re-saving a note's own title is accepted while
 * colliding with a different note is a `422`.
 */
final class UniqueNoteTitleRule implements ValidationRule
{
    public function __construct(private readonly InMemoryStore $store) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!$value instanceof Note) {
            return;
        }

        foreach ($this->store->all() as $other) {
            if (!$other instanceof Note || $other->id === $value->id) {
                continue;
            }
            if ($other->title === $value->title) {
                $fail('A note with this title already exists.');

                return;
            }
        }
    }
}
