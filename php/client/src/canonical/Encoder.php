<?php

declare(strict_types=1);

namespace Decionis\Client\Canonical;

/**
 * Walks a JSON value and writes it compactly, with object keys in the order the profile defines.
 */
abstract class Encoder implements Canonicalizer
{
    /**
     * @param list<string> $keys
     * @return list<string>
     */
    abstract protected function order(array $keys): array;

    public function encode(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => Scalar::integer($value),
            is_float($value) => Scalar::float($value),
            is_string($value) => Scalar::string($value),
            is_array($value) && array_is_list($value) => $this->list($value),
            is_array($value) => $this->members($value),
            $value instanceof \JsonSerializable => $this->encode($value->jsonSerialize()),
            $value instanceof \stdClass => $this->members(get_object_vars($value)),
            default => throw new CanonicalException('CANONICAL_VALUE_NOT_JSON'),
        };
    }

    /** @param list<mixed> $items */
    private function list(array $items): string
    {
        return '[' . implode(',', array_map(fn (mixed $item): string => $this->encode($item), $items)) . ']';
    }

    /** @param array<array-key, mixed> $members */
    private function members(array $members): string
    {
        $byKey = [];
        foreach ($members as $key => $member) {
            $byKey[(string) $key] = $member;
        }
        $parts = [];
        foreach ($this->order(array_map('strval', array_keys($byKey))) as $key) {
            $parts[] = Scalar::string($key) . ':' . $this->encode($byKey[$key]);
        }
        return '{' . implode(',', $parts) . '}';
    }
}
