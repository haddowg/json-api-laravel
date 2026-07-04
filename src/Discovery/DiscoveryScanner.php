<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Discovery;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface;
use haddowg\JsonApiLaravel\DataProvider\DataProviderInterface;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApiLaravel\Validation\ConstraintTranslatorInterface;

/**
 * The Laravel-native replacement for the Symfony bundle's autoconfiguration +
 * compiler passes (PLAN decision 3): a filesystem scan of the configured paths for
 * JSON:API capability classes.
 *
 * For each discovered class it reads only what a route/server needs, **statically**:
 * a resource's `type`/`uriType`/server assignment/operation allow-list come from its
 * static `$type`/`$uriType` and its optional {@see AsJsonApiResource} attribute, read
 * by reflection — the class is autoloaded but never instantiated (core's lazy
 * resolver constructs it via the container on first use).
 *
 * It also collects concrete {@see DataProviderInterface} / {@see DataPersisterInterface}
 * implementers so an application's own container-constructible SPI implementations are
 * registered by scanning too. (The reference in-memory provider is registered
 * explicitly through `JsonApi::provider()` since it carries seed data the container
 * cannot supply.)
 */
final class DiscoveryScanner
{
    /**
     * Scans `$paths` (recursively) plus any `$explicitClasses`, returning the
     * discovered capability classes.
     *
     * @param list<string>       $paths           directories to scan (non-existent paths are skipped)
     * @param list<class-string> $explicitClasses classes registered directly via `JsonApi::register()`, scanned without a filesystem walk
     */
    public function scan(array $paths, array $explicitClasses = []): DiscoveryResult
    {
        $classes = $explicitClasses;
        foreach ($paths as $path) {
            foreach ($this->classesIn($path) as $class) {
                $classes[] = $class;
            }
        }

        /** @var array<class-string, true> $seen */
        $seen = [];
        $resources = [];
        $providers = [];
        $persisters = [];
        $translators = [];

        foreach ($classes as $class) {
            if (isset($seen[$class])) {
                continue;
            }
            $seen[$class] = true;

            if (!\class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            if ($reflection->isSubclassOf(AbstractResource::class)) {
                $descriptor = $this->describeResource($reflection);
                if ($descriptor !== null) {
                    $resources[] = $descriptor;
                }

                continue;
            }

            if ($reflection->implementsInterface(DataProviderInterface::class)) {
                $providers[] = $class;

                continue;
            }

            if ($reflection->implementsInterface(DataPersisterInterface::class)) {
                $persisters[] = $class;

                continue;
            }

            if ($reflection->implementsInterface(ConstraintTranslatorInterface::class)) {
                $translators[] = $class;
            }
        }

        return new DiscoveryResult($resources, $providers, $persisters, $translators);
    }

    /**
     * Builds a {@see ResourceDescriptor} from a resource class's static declaration,
     * or `null` when the class declares no non-empty `type` (a misconfigured resource
     * with no discoverable type — skipped rather than fatal).
     *
     * @param \ReflectionClass<AbstractResource> $reflection
     */
    private function describeResource(\ReflectionClass $reflection): ?ResourceDescriptor
    {
        $attribute = $this->attribute($reflection);

        $staticType = $reflection->getStaticPropertyValue('type', '');
        $type = \is_string($staticType) ? $staticType : '';
        if ($attribute !== null && $attribute->type !== null) {
            $type = $attribute->type;
        }
        if ($type === '') {
            return null;
        }

        $staticUriType = $reflection->getStaticPropertyValue('uriType', '');
        $uriType = \is_string($staticUriType) && $staticUriType !== '' ? $staticUriType : $type;

        /** @var class-string<AbstractResource> $class */
        $class = $reflection->getName();

        return new ResourceDescriptor(
            $class,
            $type,
            $uriType,
            $this->servers($attribute),
            $this->operations($attribute),
            $attribute?->policy,
            $attribute !== null ? $attribute->abilities : [],
        );
    }

    /**
     * @param \ReflectionClass<AbstractResource> $reflection
     */
    private function attribute(\ReflectionClass $reflection): ?AsJsonApiResource
    {
        $attributes = $reflection->getAttributes(AsJsonApiResource::class);
        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /**
     * The server name(s) a resource is exposed on: the attribute's `server` (a single
     * name or a list), defaulting to the implicit `default` server.
     *
     * @return list<string>
     */
    private function servers(?AsJsonApiResource $attribute): array
    {
        $server = $attribute?->server;
        if ($server === null) {
            return ['default'];
        }

        if (\is_string($server)) {
            return [$server];
        }

        return \array_values($server);
    }

    /**
     * The exposed operations as {@see Operation} case-value strings: the attribute's
     * `readOnly` shorthand (the two fetch operations), an explicit `operations`
     * allow-list, or — the default — all five operations.
     *
     * @return list<string>
     */
    private function operations(?AsJsonApiResource $attribute): array
    {
        if ($attribute !== null && $attribute->readOnly) {
            return [Operation::FetchCollection->value, Operation::FetchOne->value];
        }

        if ($attribute !== null && $attribute->operations !== []) {
            return \array_values(\array_map(static fn(Operation $op): string => $op->value, $attribute->operations));
        }

        return \array_map(static fn(Operation $op): string => $op->value, Operation::cases());
    }

    /**
     * The fully-qualified class names declared in the PHP files under `$path`
     * (recursively). A non-existent path yields none.
     *
     * @return list<class-string>
     */
    private function classesIn(string $path): array
    {
        if (!\is_dir($path)) {
            return [];
        }

        $classes = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->classInFile($file->getPathname());
            if ($class !== null) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * The fully-qualified name of the class/enum declared in `$file`, extracted by
     * tokenising the source (so the file is never executed to discover it), or `null`
     * when the file declares no top-level class.
     *
     * @return class-string|null
     */
    private function classInFile(string $file): ?string
    {
        $contents = @\file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $tokens = \token_get_all($contents);
        $namespace = '';
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }

            if ($token[0] === \T_NAMESPACE) {
                $namespace = $this->readNamespace($tokens, $i);

                continue;
            }

            if ($token[0] === \T_CLASS && !$this->isAnonymousOrModifier($tokens, $i)) {
                $name = $this->readClassName($tokens, $i);
                if ($name === null) {
                    return null;
                }

                /** @var class-string $fqcn */
                $fqcn = $namespace === '' ? $name : $namespace . '\\' . $name;

                return $fqcn;
            }
        }

        return null;
    }

    /**
     * Reads the namespace name following a `T_NAMESPACE` token.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function readNamespace(array $tokens, int $start): string
    {
        $namespace = '';
        $count = \count($tokens);

        for ($i = $start + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === ';' || $token === '{') {
                break;
            }

            if (\is_array($token) && \in_array($token[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NS_SEPARATOR], true)) {
                $namespace .= $token[1];
            }
        }

        return \trim($namespace);
    }

    /**
     * Reads the class name (the `T_STRING` following the `T_CLASS` keyword).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function readClassName(array $tokens, int $start): ?string
    {
        $count = \count($tokens);

        for ($i = $start + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (\is_array($token) && $token[0] === \T_STRING) {
                return $token[1];
            }
        }

        return null;
    }

    /**
     * Whether a `T_CLASS` token is NOT a named top-level class declaration, so the
     * scanner should skip it and keep looking for a real declaration later in the file.
     * Two forms carry a tell-tale preceding significant token:
     *  - `Foo::class` (a class-constant reference) — preceded by `::` (`T_DOUBLE_COLON`);
     *  - `new class extends … {}` (an anonymous class) — preceded by `new` (`T_NEW`),
     *    whose `readClassName()` would otherwise pick up the *parent* class name.
     * Declaration modifiers (`abstract`/`final`/`readonly class`) are NOT matched here —
     * those precede a real named declaration and must be read.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function isAnonymousOrModifier(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return \is_array($token) && \in_array($token[0], [\T_DOUBLE_COLON, \T_NEW], true);
        }

        return false;
    }
}
