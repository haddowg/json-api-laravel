<?php

declare(strict_types=1);

/*
 * Exports the Symfony bundle's music-catalog OpenAPI documents for the byte-compat check
 * (PLAN decision 11). It boots the bundle example's `MusicCatalogKernel` from the sibling
 * checkout and runs the bundle's own `json-api:openapi:export` command for each server,
 * writing `build/symfony-<server>.json`.
 *
 * This lives in THIS repo (never edits the bundle) and only consumes the bundle's public
 * console command through its example kernel — the same command a bundle user would run.
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
    \fwrite(\STDERR, "export-symfony-openapi: bundle autoloader not found at {$autoload}\n");
    exit(1);
}

require $autoload;

if (!\is_dir($outputDir) && !\mkdir($outputDir, 0o755, true) && !\is_dir($outputDir)) {
    \fwrite(\STDERR, "export-symfony-openapi: could not create {$outputDir}\n");
    exit(1);
}

$kernel = new \haddowg\JsonApiBundle\Examples\MusicCatalog\MusicCatalogKernel('test', true);
$application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$application->setAutoExit(false);

foreach ($servers as $server) {
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
        \fwrite(\STDERR, \sprintf("export-symfony-openapi: export failed for server '%s' (exit %d): %s\n", $server, $code, $output->fetch()));
        exit($code);
    }
}
