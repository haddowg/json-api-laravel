<?php

declare(strict_types=1);

/*
 * Normalizes an exported standalone JSON-Schema document for the byte-compatibility diff
 * (PLAN decision 11). This is the schema counterpart to bin/normalize-openapi.php: the
 * `jsonapi:jsonschema:export` / `json-api:json-schema:export` commands emit a single
 * object keyed by JSON:API type, each value the type's self-contained JSON Schema
 * 2020-12 resource object. That document is required to be byte-identical between this
 * package and the Symfony bundle for an identical domain.
 *
 * Unlike the OpenAPI document it carries NO platform-specific top-level block — no `info`,
 * no `servers[].url`, nothing derived from the app's base URI or config. Its two identity
 * keywords (`$schema` = the 2020-12 dialect, `$id` = `urn:jsonapi:schema:<type>`) are
 * fixed by both frameworks' JsonSchemaFactory and MUST match verbatim — sentinel-replacing
 * them would blind the diff to a real drift. So this normalizer applies ZERO sentinel
 * rules (every rule stops protecting surface); its only job is to re-encode with the same
 * fixed pretty format the OpenAPI normalizer uses, so the two inputs are compared under
 * identical whitespace regardless of how each command chose to encode. Every structural
 * byte — types, attributes, the identity keywords — is compared verbatim.
 *
 * Usage:  php bin/normalize-json-schema.php <path-to-schemas.json>
 *         (writes the normalized document to stdout; non-zero exit on read/parse error)
 */

if ($argc < 2) {
    \fwrite(\STDERR, "usage: php bin/normalize-json-schema.php <schemas.json>\n");
    exit(2);
}

$path = $argv[1];
$raw = @\file_get_contents($path);
if ($raw === false) {
    \fwrite(\STDERR, \sprintf("normalize-json-schema: cannot read %s\n", $path));
    exit(2);
}

try {
    /** @var object $doc */
    $doc = \json_decode($raw, false, 512, \JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    \fwrite(\STDERR, \sprintf("normalize-json-schema: invalid JSON in %s: %s\n", $path, $e->getMessage()));
    exit(2);
}

if (!$doc instanceof \stdClass) {
    \fwrite(\STDERR, \sprintf("normalize-json-schema: %s is not a JSON object\n", $path));
    exit(2);
}

echo \json_encode($doc, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
