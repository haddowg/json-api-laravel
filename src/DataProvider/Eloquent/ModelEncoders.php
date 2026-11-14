<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Picks the id encoder a filter decodes a wire id with when it compares a model's key.
 * The type the comparison targets decides when it serves the model. Otherwise the types
 * serving the model decide when they share one encoder, or none, and else the types the
 * filter's declaration names do, when those that serve the model share one. Any other
 * case is ambiguous, and {@see ambiguity()} reports the candidate types.
 *
 * @internal
 */
final readonly class ModelEncoders
{
    /**
     * @param array<string, class-string<Model>>      $modelByType a `type → model class` map
     * @param \Closure(string): ?IdEncoderInterface   $encoderOf   a type's id encoder, or null when it has none
     */
    public function __construct(
        private array $modelByType,
        private \Closure $encoderOf,
    ) {}

    /**
     * The encoder for a key of `$class`, or null when wire ids are its keys.
     *
     * @param class-string<Model> $class
     * @param list<string>        $named the types the filter's declaration names
     *
     * @throws AmbiguousModelEncoder when nothing settles which serving type's encoder applies
     */
    public function for(string $class, ?string $type, array $named): ?IdEncoderInterface
    {
        $serving = $this->serving($class);
        if ($serving === []) {
            return null;
        }

        if ($type !== null && \in_array($type, $serving, true)) {
            return ($this->encoderOf)($type);
        }

        $chosen = $this->shared($serving) ?? $this->shared(\array_values(\array_intersect($serving, $named)));
        if ($chosen === null) {
            throw new AmbiguousModelEncoder($class, $serving);
        }

        return $chosen[0];
    }

    /**
     * The types serving `$class` with differing encoders when the types `$named` don't
     * settle which applies, or null when the encoder is settled.
     *
     * @param class-string<Model> $class
     * @param list<string>        $named
     *
     * @return list<string>|null
     */
    public function ambiguity(string $class, array $named): ?array
    {
        try {
            $this->for($class, null, $named);
        } catch (AmbiguousModelEncoder $e) {
            return $e->candidates;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function serving(string $class): array
    {
        return \array_values(\array_map('strval', \array_keys($this->modelByType, $class, true)));
    }

    /**
     * The one encoder every type in `$types` has, wrapped so a shared absence of one reads
     * apart from no answer: `[encoder]` or `[null]`, and null when the list is empty or the
     * types differ.
     *
     * @param list<string> $types
     *
     * @return array{0: ?IdEncoderInterface}|null
     */
    private function shared(array $types): ?array
    {
        if ($types === []) {
            return null;
        }

        $first = ($this->encoderOf)($types[0]);
        foreach (\array_slice($types, 1) as $type) {
            $encoder = ($this->encoderOf)($type);
            if (($first === null) !== ($encoder === null) || ($first !== null && $first != $encoder)) {
                return null;
            }
        }

        return [$first];
    }
}
