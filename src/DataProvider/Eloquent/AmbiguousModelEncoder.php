<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

/**
 * Several types serve a model a filter compares with different id encoders, and nothing
 * the filter declares names which one decodes its values.
 *
 * @internal
 */
final class AmbiguousModelEncoder extends \LogicException
{
    /**
     * @param list<string> $candidates the types serving the model
     */
    public function __construct(
        public readonly string $model,
        public readonly array $candidates,
    ) {
        parent::__construct(\sprintf('The types %s serve %s with different id encoders.', self::quoted($candidates), $model));
    }

    /**
     * The message for a declared filter whose path ends on such a model.
     *
     * @param list<string> $candidates
     */
    public static function forFilter(string $type, string $key, string $path, string $model, array $candidates): string
    {
        return \sprintf(
            'Filter "%s" of type "%s" ends its path "%s" on %s, which the types %s serve with different id encoders, and no type the declaration names serves it. End the path on a relationship of a type that serves it, or give those types the same encoder.',
            $key,
            $type,
            $path,
            $model,
            self::quoted($candidates),
        );
    }

    /**
     * @param list<string> $types
     */
    private static function quoted(array $types): string
    {
        $quoted = \array_map(static fn(string $type): string => '"' . $type . '"', $types);
        $last = \array_pop($quoted);

        return $quoted === [] ? (string) $last : \implode(', ', $quoted) . ' and ' . $last;
    }
}
