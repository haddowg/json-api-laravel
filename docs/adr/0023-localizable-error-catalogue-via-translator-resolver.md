# Localizable error catalogue via a translator-backed message resolver

Core made every error's `title`/`detail` a template resolvable per stable `code`
(core ADR 0128, and the Symfony bundle's parity twin). The package binds that seam to
the Laravel translator: a `TranslatorErrorMessageResolver` looks up
`<CODE>.title` / `<CODE>.detail` in the `jsonapi-errors` translation group, returns the
(localized) template or `null` when the line is absent, and the `ServerFactory` threads
it onto every server's `Server`.

The translator is always available in Laravel, so — unlike the bundle's `symfony/translation`
gate — the resolver is always wired; with no `jsonapi-errors` lines it resolves `null` and
core renders its inline English copy, byte-identical to today. No replacements are passed
to the translator, so a translated line keeps its `{placeholder}` tokens for core to
interpolate from the error's context (`{mediaType}`, not Laravel's `:mediaType`) — locale
is the app's current locale, so negotiation stays framework-native. Because core applies
the resolver uniformly to every rendered error, the validator's `VALIDATION_FAILED` `422`
title localizes through the same group.
