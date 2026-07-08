<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Server;

use haddowg\JsonApiLaravel\Server\TranslatorErrorMessageResolver;
use Illuminate\Contracts\Translation\Translator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The package's {@see TranslatorErrorMessageResolver} maps a core error `code` onto a
 * Laravel translation lookup (`<CODE>.title` / `<CODE>.detail` in the `jsonapi-errors`
 * group), returning the (localized) template or `null` when the line is absent. That a
 * returned template then localizes the rendered title/detail (and the `VALIDATION_FAILED`
 * 422 title) once bound on the Server is covered by core's own ErrorMessageResolver
 * suite; here we pin the package's lookup behaviour.
 *
 * @internal
 */
#[CoversClass(TranslatorErrorMessageResolver::class)]
final class TranslatorErrorMessageResolverTest extends TestCase
{
    #[Test]
    public function itResolvesTitleAndDetailByCodeFromTheJsonapiErrorsGroup(): void
    {
        $resolver = new TranslatorErrorMessageResolver($this->translator([
            'jsonapi-errors.RESOURCE_NOT_FOUND.title' => 'Ressource introuvable',
            'jsonapi-errors.MEDIA_TYPE_UNSUPPORTED.detail' => "Le type de média '{mediaType}' n'est pas supporté.",
        ]));

        self::assertSame('Ressource introuvable', $resolver->title('RESOURCE_NOT_FOUND'));
        // The template is returned verbatim — {placeholder} tokens intact for core to fill.
        self::assertSame(
            "Le type de média '{mediaType}' n'est pas supporté.",
            $resolver->detail('MEDIA_TYPE_UNSUPPORTED'),
        );
    }

    #[Test]
    public function aMissingLineResolvesToNullSoTheDefaultIsKeptPerSlot(): void
    {
        $resolver = new TranslatorErrorMessageResolver($this->translator([
            'jsonapi-errors.RESOURCE_NOT_FOUND.title' => 'Ressource introuvable',
        ]));

        // Title known, detail absent → per-slot fallback: detail stays null.
        self::assertSame('Ressource introuvable', $resolver->title('RESOURCE_NOT_FOUND'));
        self::assertNull($resolver->detail('RESOURCE_NOT_FOUND'));
        // Unknown code → both null.
        self::assertNull($resolver->title('SOMETHING_ELSE'));
        self::assertNull($resolver->detail('SOMETHING_ELSE'));
    }

    #[Test]
    public function itLooksUpTheGroupedKeyPassingNoReplacements(): void
    {
        $translator = new class extends FakeTranslator {
            /** @var list<array{key: string, replace: array<string, mixed>}> */
            public array $calls = [];

            public function get($key, array $replace = [], $locale = null): string
            {
                $this->calls[] = ['key' => $key, 'replace' => $replace];

                return $key; // simulate "missing"
            }
        };

        (new TranslatorErrorMessageResolver($translator))->title('RESOURCE_NOT_FOUND');

        self::assertSame('jsonapi-errors.RESOURCE_NOT_FOUND.title', $translator->calls[0]['key']);
        // No replacements — core, not the translator, fills the {placeholders}.
        self::assertSame([], $translator->calls[0]['replace']);
    }

    /**
     * @param array<string, string> $lines
     */
    private function translator(array $lines): Translator
    {
        return new class ($lines) extends FakeTranslator {
            /**
             * @param array<string, string> $lines
             */
            public function __construct(private readonly array $lines) {}

            public function get($key, array $replace = [], $locale = null): string
            {
                // Laravel returns the key unchanged when the line is absent.
                return $this->lines[$key] ?? $key;
            }
        };
    }
}

/**
 * Minimal {@see Translator} base for the resolver's unit tests — only `get()` matters;
 * the rest satisfy the contract.
 *
 * @internal
 */
abstract class FakeTranslator implements Translator
{
    /**
     * @param array<string, mixed> $replace
     */
    public function get($key, array $replace = [], $locale = null): string
    {
        return $key;
    }

    /**
     * @param \Countable|array<array-key, mixed>|float|int $number
     * @param array<string, mixed>                         $replace
     */
    public function choice($key, $number, array $replace = [], $locale = null): string
    {
        return $key;
    }

    public function getLocale(): string
    {
        return 'en';
    }

    public function setLocale($locale): void {}
}
