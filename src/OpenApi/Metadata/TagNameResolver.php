<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Metadata;

use Illuminate\Support\Str;

/**
 * Resolves the **default** OpenAPI tag name for a JSON:API type when none is declared
 * (PLAN decision 11): a humanized, title-cased, pluralized form of the type —
 * `blog-post` → `'Blog Posts'`, `genre` → `'Genres'`.
 *
 * It is a heuristic, always overridable by an explicit tag ref on the resource /
 * serializer / action. The type is split on `-`/`_`/camelCase boundaries into words,
 * each word title-cased; the **last** word is pluralized (a noun phrase pluralizes its
 * head noun). This mirrors the Symfony bundle's `TagNameResolver` algorithm so both
 * integrations project the same default tag for an identical type (the Phase-5
 * byte-compat mandate); it uses Laravel's {@see Str} inflector (backed by
 * doctrine/inflector) rather than a Symfony string dependency.
 *
 * @internal
 */
final class TagNameResolver
{
    /**
     * The humanized-title-case, pluralized tag name for `$type`.
     */
    public function defaultFor(string $type): string
    {
        // Normalize separators to `_` and split camelCase, then split into words.
        // Str::snake turns `blogPost`/`blog-post` (after the `-`→`_` swap) into
        // `blog_post`.
        $normalized = Str::snake(\str_replace('-', '_', $type));
        $words = \preg_split('/[\s_]+/', $normalized) ?: [];
        $words = \array_values(\array_filter($words, static fn(string $word): bool => $word !== ''));

        if ($words === []) {
            return Str::title($type);
        }

        $last = \array_key_last($words);
        $words[$last] = $this->pluralize($words[$last]);

        return \implode(' ', \array_map(
            static fn(string $word): string => Str::title($word),
            $words,
        ));
    }

    /**
     * The plural of `$word`, or the word unchanged when it already appears plural —
     * singularizing to a *different* word signals the input is already a plural (e.g.
     * `people`, `albums`), so re-pluralizing it (`peoples`, `albumss`) is suppressed.
     */
    private function pluralize(string $word): string
    {
        if (Str::singular($word) !== $word) {
            return $word;
        }

        return Str::plural($word);
    }
}
