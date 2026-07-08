<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Schema\Error\ErrorMessageResolverInterface;
use Illuminate\Contracts\Translation\Translator;

/**
 * The package's {@see ErrorMessageResolverInterface}: resolves an error's title/detail
 * template from the Laravel translator, keyed by the stable error `code`, so core's
 * error catalogue is localizable and overridable through ordinary Laravel translation
 * lines — with nothing to register per error.
 *
 * Lines live in the `jsonapi-errors` group as `<CODE>.title` / `<CODE>.detail`
 * (e.g. `lang/fr/jsonapi-errors.php`):
 *
 *     return [
 *         'RESOURCE_NOT_FOUND' => ['title' => 'Ressource introuvable'],
 *         'MEDIA_TYPE_UNSUPPORTED' => [
 *             'detail' => "Le type de média '{mediaType}' n'est pas supporté.",
 *         ],
 *     ];
 *
 * A missing line falls back to the error's own default: Laravel returns the key
 * unchanged, which maps to `null`, so a partial translation degrades gracefully per
 * slot. The returned string is a **template** — core interpolates the error's
 * `{placeholder}` context into it *after* lookup, so the lines carry `{name}` tokens,
 * not Laravel `:name` replacements (none are passed to the translator, which therefore
 * leaves the tokens intact). The locale is the app's current locale, so
 * `Accept-Language`/`setLocale` negotiation stays the framework's job.
 */
final readonly class TranslatorErrorMessageResolver implements ErrorMessageResolverInterface
{
    public const GROUP = 'jsonapi-errors';

    public function __construct(private Translator $translator) {}

    public function title(string $code): ?string
    {
        return $this->resolve($code . '.title');
    }

    public function detail(string $code): ?string
    {
        return $this->resolve($code . '.detail');
    }

    private function resolve(string $item): ?string
    {
        $key = self::GROUP . '.' . $item;

        // No replacements: core fills the {placeholder} tokens after lookup. Laravel
        // returns the key unchanged when the line is absent (in every fallback locale),
        // which means "no override", so keep the default.
        $translated = $this->translator->get($key);

        return \is_string($translated) && $translated !== $key ? $translated : null;
    }
}
