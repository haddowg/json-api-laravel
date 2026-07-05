<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * The declarative deprecation signal a JSON:API type declares via
 * `#[AsJsonApiResource(deprecation: …, sunset: …)]` or the global
 * `jsonapi.defaults.deprecation`/`sunset` config keys (PLAN decision 12, bundle ADR
 * 0054). A pure, immutable value object of scalars.
 *
 * Header semantics:
 *  - **`Deprecation`** (the IETF Deprecation header field,
 *    `draft-ietf-httpapi-deprecation-header`) announces the resource is deprecated.
 *    `deprecation: true` emits a bare `Deprecation: true`; `deprecation: '<date>'`
 *    emits the deprecation date. The value is passed through verbatim.
 *  - **`Sunset`** (RFC 8594) announces when the resource will stop responding.
 *    `sunset: '<HTTP-date>'` emits `Sunset: <HTTP-date>` (passed through verbatim).
 *    When `$sunsetLink` is configured, a companion `Link: <uri>; rel="sunset"` (the
 *    RFC 8594 `sunset` link relation) is emitted alongside it.
 *
 * Unlike {@see CacheHeaders} (safe `GET`s only), deprecation/sunset are emitted on
 * **every** response for the type — reads and writes alike — because a deprecated
 * endpoint is deprecated regardless of method.
 */
final readonly class DeprecationHeaders
{
    /**
     * @param bool|string|null $deprecation `true` => a bare `Deprecation: true`; a non-empty string => `Deprecation: <value>`; `false`/`null` => no `Deprecation` header
     * @param string|null      $sunset      the `Sunset` HTTP-date (passed through verbatim), or `null` for none
     * @param string|null      $sunsetLink  a URI for the companion `Link: <uri>; rel="sunset"`, emitted only when `$sunset` is also set
     */
    public function __construct(
        public bool|string|null $deprecation = null,
        public ?string $sunset = null,
        public ?string $sunsetLink = null,
    ) {}

    /**
     * Rebuilds a {@see DeprecationHeaders} from the scalar shape discovery + the
     * global config keys use.
     *
     * @param array{
     *     deprecation?: bool|string|null,
     *     sunset?: string|null,
     *     sunset_link?: string|null,
     * } $config
     */
    public static function fromArray(array $config): self
    {
        $deprecation = $config['deprecation'] ?? null;
        if (\is_string($deprecation) && $deprecation === '') {
            $deprecation = null;
        }

        $sunset = $config['sunset'] ?? null;
        $sunsetLink = $config['sunset_link'] ?? null;

        return new self(
            deprecation: \is_bool($deprecation) || \is_string($deprecation) ? $deprecation : null,
            sunset: \is_string($sunset) && $sunset !== '' ? $sunset : null,
            sunsetLink: \is_string($sunsetLink) && $sunsetLink !== '' ? $sunsetLink : null,
        );
    }

    /**
     * A resource-level {@see DeprecationHeaders} layered over the global default:
     * each of the three values falls back to the default's when this object leaves it
     * unset.
     */
    public function mergeOver(self $default): self
    {
        return new self(
            deprecation: $this->deprecation ?? $default->deprecation,
            sunset: $this->sunset ?? $default->sunset,
            sunsetLink: $this->sunsetLink ?? $default->sunsetLink,
        );
    }

    /**
     * Whether this object declares neither a deprecation nor a sunset — applies
     * nothing.
     */
    public function isEmpty(): bool
    {
        return ($this->deprecation === null || $this->deprecation === false)
            && $this->sunset === null;
    }

    /**
     * Writes the `Deprecation`/`Sunset` (+ optional sunset `Link`) headers onto
     * `$response`, but only those the app has not already set explicitly (an app's
     * explicit header always wins — the middleware must not clobber it).
     */
    public function applyTo(Response $response): void
    {
        if (!$response->headers->has('Deprecation')) {
            if ($this->deprecation === true) {
                $response->headers->set('Deprecation', 'true');
            } elseif (\is_string($this->deprecation)) {
                $response->headers->set('Deprecation', $this->deprecation);
            }
        }

        if ($this->sunset !== null && !$response->headers->has('Sunset')) {
            $response->headers->set('Sunset', $this->sunset);

            if ($this->sunsetLink !== null) {
                $response->headers->set(
                    'Link',
                    \sprintf('<%s>; rel="sunset"', $this->sunsetLink),
                    replace: false,
                );
            }
        }
    }
}
