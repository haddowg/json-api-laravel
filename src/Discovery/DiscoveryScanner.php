<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Discovery;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiLaravel\Action\ActionDescriptor;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionOutput;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiHydrator;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
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
        $serializers = [];
        $hydrators = [];
        $providers = [];
        $persisters = [];
        $translators = [];
        $actions = [];

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

            // A standalone serializer is a SerializerInterface carrying #[AsJsonApiSerializer]
            // and no AbstractResource (checked above) — a type whose wire shape is hand-written
            // (PLAN decision 3, the Laravel twin of bundle ADR 0024). A serializer-override class
            // (referenced from #[AsJsonApiResource(serializer:)]) carries no attribute and so is
            // never mis-classified here; it is resolved through its resource instead. The same
            // applies to the standalone hydrator (the decoupled write half): both attributes may
            // sit on ONE class implementing both interfaces, so the two checks are independent —
            // a dual-attribute class lands in both buckets.
            $standalone = false;
            if ($reflection->getAttributes(AsJsonApiSerializer::class) !== [] && $reflection->implementsInterface(SerializerInterface::class)) {
                $descriptor = $this->describeSerializer($reflection);
                if ($descriptor !== null) {
                    $serializers[] = $descriptor;
                }

                $standalone = true;
            }

            if ($reflection->getAttributes(AsJsonApiHydrator::class) !== [] && $reflection->implementsInterface(HydratorInterface::class)) {
                $descriptor = $this->describeHydrator($reflection);
                if ($descriptor !== null) {
                    $hydrators[] = $descriptor;
                }

                $standalone = true;
            }

            if ($standalone) {
                continue;
            }

            // A custom-action handler is a class carrying #[AsJsonApiAction] and
            // implementing ActionHandlerInterface — discovered by the same scan, no
            // AbstractResource sugar (PLAN decision 12).
            if ($reflection->getAttributes(AsJsonApiAction::class) !== [] && $reflection->implementsInterface(ActionHandlerInterface::class)) {
                $actions[] = $this->describeAction($reflection);

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

        return new DiscoveryResult($resources, $providers, $persisters, $translators, $actions, $serializers, $hydrators);
    }

    /**
     * Builds a {@see SerializerDescriptor} from a standalone serializer's
     * {@see AsJsonApiSerializer} attribute, or `null` when it declares no non-empty
     * `type` (a misconfigured capability with no discoverable type — skipped rather than
     * fatal). The URI segment defaults to the type (the bundle's descriptor rule); the
     * operation allow-list is taken verbatim — **empty stays empty** (serialize-only, no
     * endpoints), the deliberate asymmetry against an `AbstractResource`.
     *
     * @param \ReflectionClass<object> $reflection
     */
    private function describeSerializer(\ReflectionClass $reflection): ?SerializerDescriptor
    {
        /** @var AsJsonApiSerializer $attribute */
        $attribute = $reflection->getAttributes(AsJsonApiSerializer::class)[0]->newInstance();

        if ($attribute->type === '') {
            return null;
        }

        /** @var class-string<SerializerInterface> $class */
        $class = $reflection->getName();

        return new SerializerDescriptor(
            $class,
            $attribute->type,
            $attribute->type,
            $this->capabilityServers($attribute->server),
            \array_values(\array_map(static fn(Operation $op): string => $op->value, $attribute->operations)),
            \array_values($attribute->tags),
        );
    }

    /**
     * Builds a {@see HydratorDescriptor} from a standalone hydrator's
     * {@see AsJsonApiHydrator} attribute, or `null` when it declares no non-empty `type`
     * (a misconfigured capability with no discoverable type — skipped rather than fatal).
     * A hydrator carries no operation allow-list of its own: endpoints are opened by the
     * paired serializer's allow-list, which the hydrator's presence makes write-legal.
     *
     * @param \ReflectionClass<object> $reflection
     */
    private function describeHydrator(\ReflectionClass $reflection): ?HydratorDescriptor
    {
        /** @var AsJsonApiHydrator $attribute */
        $attribute = $reflection->getAttributes(AsJsonApiHydrator::class)[0]->newInstance();

        if ($attribute->type === '') {
            return null;
        }

        /** @var class-string<HydratorInterface> $class */
        $class = $reflection->getName();

        return new HydratorDescriptor(
            $class,
            $attribute->type,
            $this->capabilityServers($attribute->server),
        );
    }

    /**
     * The server name(s) a standalone capability is exposed on: the attribute's `server`
     * (a single name or a list), defaulting to the implicit `default` server.
     *
     * @param string|list<string>|null $server
     *
     * @return list<string>
     */
    private function capabilityServers(string|array|null $server): array
    {
        if ($server === null) {
            return ['default'];
        }

        if (\is_string($server)) {
            return [$server];
        }

        return \array_values($server);
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
            $this->headers($attribute),
            $attribute !== null ? \array_values($attribute->tags) : [],
            $this->overrideClass($attribute?->serializer, SerializerInterface::class, 'serializer', $class),
            $this->overrideClass($attribute?->hydrator, HydratorInterface::class, 'hydrator', $class),
        );
    }

    /**
     * Validates a `#[AsJsonApiResource(serializer:/hydrator:)]` override class-string
     * (ADR 0015): the named class must exist and implement its core contract, so a typo'd
     * or mis-typed override fails discovery loudly instead of surfacing as a runtime
     * resolver error — the Laravel twin of the bundle compiler pass's guard. The
     * "registered service" half of that guard has no equivalent here: Laravel's container
     * constructs any concrete class, resolving bound constructor dependencies as it goes.
     *
     * @template T of object
     *
     * @param class-string<T> $contract
     *
     * @return class-string<T>|null
     */
    private function overrideClass(?string $override, string $contract, string $concern, string $resource): ?string
    {
        if ($override === null) {
            return null;
        }

        if (!\class_exists($override) || !\is_a($override, $contract, true)) {
            throw new \LogicException(\sprintf(
                'The %s "%s" declared by #[AsJsonApiResource] on "%s" must be a class implementing %s.',
                $concern,
                $override,
                $resource,
                $contract,
            ));
        }

        return $override;
    }

    /**
     * Projects a resource's declarative response-header config into the scalar
     * `{cache, cache_operations, deprecation}` shape the
     * {@see \haddowg\JsonApiLaravel\Http\ResponseHeadersRegistry} consumes (kept as
     * scalars so the discovery snapshot stays cacheable). The `#[AsJsonApiResource]`
     * `cacheHeaders` map's optional nested `operations` key is lifted out to
     * `cache_operations`; the `deprecation`/`sunset`/`sunsetLink` params fold into a
     * single `deprecation` sub-map. A resource declaring none contributes `[]`.
     *
     * @return array{cache?: array<string, mixed>, cache_operations?: array<string, array<string, mixed>>, deprecation?: array<string, mixed>}
     */
    private function headers(?AsJsonApiResource $attribute): array
    {
        if ($attribute === null) {
            return [];
        }

        $headers = [];

        $cache = $attribute->cacheHeaders;
        if ($cache !== []) {
            $operations = $cache['operations'] ?? null;
            unset($cache['operations']);

            if ($cache !== []) {
                $headers['cache'] = $cache;
            }
            if (\is_array($operations) && $operations !== []) {
                /** @var array<string, array<string, mixed>> $operations */
                $headers['cache_operations'] = $operations;
            }
        }

        if ($attribute->deprecation !== null || $attribute->sunset !== null || $attribute->sunsetLink !== null) {
            $headers['deprecation'] = [
                'deprecation' => $attribute->deprecation,
                'sunset' => $attribute->sunset,
                'sunset_link' => $attribute->sunsetLink,
            ];
        }

        return $headers;
    }

    /**
     * Builds an {@see ActionDescriptor} from a custom-action handler's
     * {@see AsJsonApiAction} attribute, applying the decoupled-document defaults:
     * `inputType`/`outputType` resolve to the mount `type` when the attribute left them
     * null, and a `returns204`/`outputMeta` action carries the empty-string `outputType`
     * sentinel (no response resource) with the matching {@see ActionOutput}.
     *
     * @param \ReflectionClass<object> $reflection
     */
    private function describeAction(\ReflectionClass $reflection): ActionDescriptor
    {
        /** @var AsJsonApiAction $attribute */
        $attribute = $reflection->getAttributes(AsJsonApiAction::class)[0]->newInstance();

        $this->guardActionHandlerOutput($reflection, $attribute);

        $output = match (true) {
            $attribute->returns204 => ActionOutput::None,
            $attribute->outputMeta => ActionOutput::Meta,
            default => ActionOutput::Document,
        };

        // Document mode carries the resolved output type; a 204/meta action carries the
        // empty-string "no response resource" sentinel.
        $outputType = $output === ActionOutput::Document
            ? ($attribute->outputType ?? $attribute->type)
            : '';

        /** @var class-string<ActionHandlerInterface> $handlerClass */
        $handlerClass = $reflection->getName();

        return new ActionDescriptor(
            $attribute->type,
            $attribute->path,
            $attribute->methods,
            $attribute->scope,
            $attribute->input,
            $attribute->input === ActionInput::Document ? ($attribute->inputType ?? $attribute->type) : $attribute->type,
            $outputType,
            $output,
            $attribute->ability,
            $handlerClass,
            $attribute->server ?? 'default',
            $attribute->name,
            $attribute->tags,
            $attribute->asLink,
        );
    }

    /**
     * Guards a discovered action handler's declared output shape against its attribute
     * flags (the Laravel twin of the bundle's `ResourceLocatorPass::guardActionHandlerOutput`):
     * a handler whose `handle()` return type is narrowed to exactly {@see NoContentResponse}
     * must declare `returns204`, and one narrowed to exactly {@see MetaResponse} must declare
     * `outputMeta` — so the generated OpenAPI response can never drift from the shape the
     * handler actually returns. A handler that keeps the interface's union return type (or
     * any other single non-narrowed type) declares no single body shape, so it is not
     * constrained here (the flags then govern the projection alone); discovery fails loudly
     * only on a genuine narrowing/flag mismatch.
     *
     * @param \ReflectionClass<object> $reflection
     */
    private function guardActionHandlerOutput(\ReflectionClass $reflection, AsJsonApiAction $attribute): void
    {
        if (!$reflection->hasMethod('handle')) {
            return;
        }

        $returnType = $reflection->getMethod('handle')->getReturnType();
        if (!$returnType instanceof \ReflectionNamedType) {
            return;
        }

        $name = $returnType->getName();

        if ($name === NoContentResponse::class && !$attribute->returns204) {
            throw new \LogicException(\sprintf(
                'The JSON:API action "%s" on type "%s" has a handler (%s) returning NoContentResponse but does not '
                . 'declare returns204; a body-less action must declare returns204 so the generated document advertises a 204.',
                $attribute->path,
                $attribute->type,
                $reflection->getName(),
            ));
        }

        if ($name === MetaResponse::class && !$attribute->outputMeta) {
            throw new \LogicException(\sprintf(
                'The JSON:API action "%s" on type "%s" has a handler (%s) returning MetaResponse but does not declare '
                . 'outputMeta; a meta-only action must declare outputMeta so the generated document advertises a meta document.',
                $attribute->path,
                $attribute->type,
                $reflection->getName(),
            ));
        }
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
