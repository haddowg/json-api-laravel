<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Support;

use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;
use Doctrine\Inflector\WordInflector;
use Illuminate\Support\Pluralizer;

/**
 * Swaps the inflector behind `Str::singular()` / `Str::plural()` for one that counts
 * singularizations, so a test can assert a code path runs no inflection at all.
 *
 * @internal
 */
final class InflectionSpy
{
    public static int $singularized = 0;

    public static function install(): void
    {
        $real = InflectorFactory::createForLanguage('english')->build();
        self::$singularized = 0;

        // Pluralizer documents the property as its own type, so set it reflectively.
        (new \ReflectionProperty(Pluralizer::class, 'inflector'))->setValue(null, new Inflector(
            new class ($real) implements WordInflector {
                public function __construct(private readonly Inflector $real) {}

                public function inflect(string $word): string
                {
                    ++InflectionSpy::$singularized;

                    return $this->real->singularize($word);
                }
            },
            new class ($real) implements WordInflector {
                public function __construct(private readonly Inflector $real) {}

                public function inflect(string $word): string
                {
                    return $this->real->pluralize($word);
                }
            },
        ));
    }

    public static function uninstall(): void
    {
        Pluralizer::useLanguage('english');
    }
}
