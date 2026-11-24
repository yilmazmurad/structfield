<?php

declare(strict_types=1);

namespace YilmazMurad\StructField;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;
use YilmazMurad\StructField\Exception\InvalidValueException;
use YilmazMurad\StructField\Exception\TypeMismatchException;

/**
 * Ordered, immutable map of Parameters (RFC 9651, Section 3.1.2), e.g. `;q=0.9;a`.
 *
 * @phpstan-type BareValue int|float|string|bool|Token|ByteSequence|Date|DisplayString
 *
 * @implements IteratorAggregate<string, BareValue>
 */
final readonly class Parameters implements StructuredValue, IteratorAggregate, Countable
{
    /** @var array<string, BareValue> */
    private array $members;

    /**
     * @param iterable<string, BareValue> $members
     *
     * @throws InvalidValueException
     */
    public function __construct(iterable $members = [])
    {
        $clean = [];
        foreach ($members as $key => $value) {
            Bare::validate($value);
            $clean[Bare::validateKey((string) $key)] = $value;
        }
        $this->members = $clean;
    }

    /**
     * @param self|iterable<string, BareValue> $members
     */
    public static function from(self|iterable $members): self
    {
        return $members instanceof self ? $members : new self($members);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->members);
    }

    /**
     * Returns the value for $key, or $default when the parameter is absent.
     *
     * @template T
     *
     * @param T $default
     *
     * @return BareValue|T
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->members[$key] ?? $default;
    }

    /**
     * Returns the value for $key as an Item (without parameters), or null.
     */
    public function item(string $key): ?Item
    {
        return isset($this->members[$key]) ? new Item($this->members[$key]) : null;
    }

    /**
     * Returns an Integer parameter, $default when absent, or throws when it has another type.
     */
    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->members[$key] ?? $default;
        if ($value !== null && !is_int($value)) {
            throw new TypeMismatchException(sprintf('Parameter "%s" is a %s, not an integer.', $key, BareType::of($value)->value));
        }

        return $value;
    }

    /**
     * Returns a Boolean parameter, $default when absent, or throws when it has another type.
     */
    public function bool(string $key, ?bool $default = null): ?bool
    {
        $value = $this->members[$key] ?? $default;
        if ($value !== null && !is_bool($value)) {
            throw new TypeMismatchException(sprintf('Parameter "%s" is a %s, not a boolean.', $key, BareType::of($value)->value));
        }

        return $value;
    }

    /**
     * @param BareValue $value
     */
    public function with(string $key, int|float|string|bool|Token|ByteSequence|Date|DisplayString $value): self
    {
        $members = $this->members;
        $members[$key] = $value; // an existing key keeps its position

        return new self($members);
    }

    public function without(string ...$keys): self
    {
        $members = $this->members;
        foreach ($keys as $key) {
            unset($members[$key]);
        }

        return new self($members);
    }

    /**
     * Returns a copy with the given parameters added (or overwritten).
     *
     * @param self|iterable<string, BareValue> $other
     */
    public function merge(self|iterable $other): self
    {
        $members = $this->members;
        foreach (self::from($other) as $key => $value) {
            $members[$key] = $value;
        }

        return new self($members);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->members);
    }

    /**
     * @return array<string, BareValue>
     */
    public function toArray(): array
    {
        return $this->members;
    }

    public function isEmpty(): bool
    {
        return $this->members === [];
    }

    public function count(): int
    {
        return count($this->members);
    }

    /**
     * @return Traversable<string, BareValue>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->members);
    }

    public function toHttpValue(): string
    {
        $out = '';
        foreach ($this->members as $key => $value) {
            $out .= ';' . $key . ($value === true ? '' : '=' . Bare::serialize($value));
        }

        return $out;
    }

    public function __toString(): string
    {
        return $this->toHttpValue();
    }
}
