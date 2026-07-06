<?php

declare(strict_types=1);

/*
 * Exports the Symfony bundle's music-catalog documents for the byte-compat check (PLAN
 * decision 11): the OpenAPI document AND the standalone JSON-Schema document, per server.
 * It boots the bundle example's `MusicCatalogKernel` from the sibling checkout once and
 * runs the bundle's own console commands for each server, writing `build/symfony-<server>.json`
 * (OpenAPI) and `build/symfony-schemas-<server>.json` (JSON-Schema).
 *
 * The two exports exercise DISTINCT projector paths — the OpenAPI document shares $ref'd
 * components; the JSON-Schema document is a per-type object keyed by type with inline
 * permissive relationships/links/meta — so both are diffed independently against this
 * package's.
 *
 * This lives in THIS repo (never edits the bundle) and only consumes the bundle's public
 * console commands through its example kernel — the same commands a bundle user would run.
 *
 * Env:  JSON_API_SYMFONY_PATH  the bundle checkout (default ../json-api-symfony)
 * Args: $1  the output directory (default: this repo's build/)
 */

$repoRoot = \dirname(__DIR__);
$bundlePath = \rtrim(\getenv('JSON_API_SYMFONY_PATH') ?: $repoRoot . '/../json-api-symfony', '/');
$outputDir = $argv[1] ?? ($repoRoot . '/build');
$servers = ['default', 'admin'];

$autoload = $bundlePath . '/vendor/autoload.php';
if (!\is_file($autoload)) {
    \fwrite(\STDERR, "export-symfony-documents: bundle autoloader not found at {$autoload}\n");
    exit(1);
}

require $autoload;

if (!\is_dir($outputDir) && !\mkdir($outputDir, 0o755, true) && !\is_dir($outputDir)) {
    \fwrite(\STDERR, "export-symfony-documents: could not create {$outputDir}\n");
    exit(1);
}

$kernel = new \haddowg\JsonApiBundle\Examples\MusicCatalog\MusicCatalogKernel('test', true);
$application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$application->setAutoExit(false);

foreach ($servers as $server) {
    // OpenAPI document — written straight to the file via --output.
    $output = new \Symfony\Component\Console\Output\BufferedOutput();
    $code = $application->run(
        new \Symfony\Component\Console\Input\ArrayInput([
            'command' => 'json-api:openapi:export',
            '--server' => $server,
            '--output' => \sprintf('%s/symfony-%s.json', $outputDir, $server),
        ]),
        $output,
    );

    if ($code !== 0) {
        \fwrite(\STDERR, \sprintf("export-symfony-documents: OpenAPI export failed for server '%s' (exit %d): %s\n", $server, $code, $output->fetch()));
        exit($code);
    }

    // JSON-Schema document — no --output, so the command emits every type as one object
    // keyed by type on stdout; capture the buffer and write it verbatim.
    $output = new \Symfony\Component\Console\Output\BufferedOutput();
    $code = $application->run(
        new \Symfony\Component\Console\Input\ArrayInput([
            'command' => 'json-api:json-schema:export',
            '--server' => $server,
        ]),
        $output,
    );

    if ($code !== 0) {
        \fwrite(\STDERR, \sprintf("export-symfony-documents: JSON-Schema export failed for server '%s' (exit %d): %s\n", $server, $code, $output->fetch()));
        exit($code);
    }

    \file_put_contents(\sprintf('%s/symfony-schemas-%s.json', $outputDir, $server), $output->fetch());
}
