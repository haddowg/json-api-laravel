<?php

declare(strict_types=1);

/*
 * Normalizes an exported OpenAPI document for the byte-compatibility diff (PLAN
 * decision 11). The projected document is required to be byte-identical between this
 * package and the Symfony bundle for an identical domain, so a `json-api-ts` codegen
 * consumes either backend unchanged. Two differences are legitimately platform-specific
 * and MUST be normalized away before diffing — and NOTHING else:
 *
 *   - `info`          — the document info block (title/version/description/contact/
 *                       license) is app config, not projected structure. Replaced with a
 *                       single sentinel so an info typo can never mask a real structural
 *                       diff (and so the two apps need not carry byte-identical prose).
 *   - `servers[].url` — each advertised server URL derives from the app's base URI /
 *                       routing prefix (`https://music.example` vs a testbench host).
 *                       Replaced with a sentinel; every other server field is left intact.
 *   - link examples   — a link example's `href` (or the string itself, when links render
 *                       as strings) starts with the first server URL (core builds it from
 *                       that URL), so that prefix is the same app config and gets the same
 *                       sentinel, at any depth of the example (a links component's example
 *                       nests one link per member). The rest of the `href`, and the
 *                       `describedby`, are compared verbatim.
 *
 * Everything else (paths, operations, parameters, components/schemas, tags, security,
 * includable enums) is compared verbatim. The script re-encodes with a fixed pretty
 * format so the two inputs are compared under identical whitespace; because BOTH sides
 * pass through this same encoder, the diff reflects only genuine structural differences.
 *
 * Usage:  php bin/normalize-openapi.php <path-to-openapi.json>
 *         (writes the normalized document to stdout; non-zero exit on read/parse error)
 */

if ($argc < 2) {
    \fwrite(\STDERR, "usage: php bin/normalize-openapi.php <openapi.json>\n");
    exit(2);
}

$path = $argv[1];
$raw = @\file_get_contents($path);
if ($raw === false) {
    \fwrite(\STDERR, \sprintf("normalize-openapi: cannot read %s\n", $path));
    exit(2);
}

try {
    /** @var object $doc */
    $doc = \json_decode($raw, false, 512, \JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    \fwrite(\STDERR, \sprintf("normalize-openapi: invalid JSON in %s: %s\n", $path, $e->getMessage()));
    exit(2);
}

if (!$doc instanceof \stdClass) {
    \fwrite(\STDERR, \sprintf("normalize-openapi: %s is not a JSON object\n", $path));
    exit(2);
}

// info — replace the whole block (app config, never projected structure).
if (\property_exists($doc, 'info')) {
    $doc->info = '__NORMALIZED_INFO__';
}

// link examples — the first server URL prefixing an example `href` (or a string example,
// when links render as strings), before servers[].url loses it below.
$serverUrl = \is_array($doc->servers ?? null) && ($doc->servers[0] ?? null) instanceof \stdClass && \is_string($doc->servers[0]->url ?? null)
    ? $doc->servers[0]->url
    : null;
if ($serverUrl !== null && $serverUrl !== '') {
    $prefixed = static function (string $value) use ($serverUrl): ?string {
        if (!\str_starts_with($value, $serverUrl)) {
            return null;
        }
        $rest = \substr($value, \strlen($serverUrl));

        return $rest === '' || \in_array($rest[0], ['/', '?', '#'], true) ? '__NORMALIZED_URL__' . $rest : null;
    };
    $example = static function (mixed $value) use (&$example, $prefixed): mixed {
        if (\is_string($value)) {
            return $prefixed($value) ?? $value;
        }
        if (\is_array($value)) {
            return \array_map($example, $value);
        }
        if ($value instanceof \stdClass) {
            foreach (\get_object_vars($value) as $key => $child) {
                $value->{$key} = $example($child);
            }
        }

        return $value;
    };
    $walk = static function (mixed $node) use (&$walk, $example): void {
        if (\is_array($node)) {
            foreach ($node as $child) {
                $walk($child);
            }

            return;
        }
        if (!$node instanceof \stdClass) {
            return;
        }
        foreach (\get_object_vars($node) as $key => $value) {
            if ($key === 'example') {
                $node->example = $example($value);
            } else {
                $walk($value);
            }
        }
    };
    $walk($doc);
}

// servers[].url — replace each URL, leave every other server field verbatim.
if (\property_exists($doc, 'servers') && \is_array($doc->servers)) {
    foreach ($doc->servers as $server) {
        if ($server instanceof \stdClass && \property_exists($server, 'url')) {
            $server->url = '__NORMALIZED_URL__';
        }
    }
}

echo \json_encode($doc, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
