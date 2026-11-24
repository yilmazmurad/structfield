<?php

declare(strict_types=1);

namespace YilmazMurad\StructField;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;
use YilmazMurad\StructField\Exception\InvalidValueException;
use YilmazMurad\StructField\Exception\ParseException;

/**
 * A Dictionary (RFC 9651, Section 3.2): `u=3, i` or `a=?0, b, c;foo=bar`.
 *
 * @phpstan-import-type BareValue from Parameters
 *
 * @implements IteratorAggregate<string, Item|InnerList>
 */
final readonly class Dictionary implements StructuredValue, IteratorAggregate, Countable
{
    /** @var array<string, Item|InnerList> */
    private array $members;

    /**
     * @param iterable<string, Item|InnerList|BareValue> $members Bare values are wrapped into Items.
     *
     * @throws InvalidValueException
     */
    public function __construct(iterable $members = [])
    {
        $clean = [];
        foreach ($members as $key => $member) {
            $clean[Bare::validateKey((string) $key)] = $member instanceof Item || $member instanceof InnerList
                ? $member
                : new Item($member);
        }
        $this->members = $clean;
    }

    /**
     * Parses a Dictionary field value.
     *
     * @param string|list<string> $httpValue One field value, or several field lines to be combined.
     *
     * @throws ParseException
     */
    public static function fromHttpValue(string|array $httpValue): self
    {
        return Parser::parseDictionary($httpValue);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->members);
    }

    /**
     * Returns the member for $key, or null when absent.
     */
    public function get(string $key): Item|InnerList|null
    {
        return $this->members[$key] ?? null;
    }

    /**
     * Returns the Item for $key; null when absent or when the member is an Inner List.
     */
    public function item(string $key): ?Item
    {
        $member = $this->members[$key] ?? null;

        return $member instanceof Item ? $member : null;
    }

    /**
     * Returns the Inner List for $key; null when absent or when the member is an Item.
     */
    public function innerList(string $key): ?InnerList
    {
        $member = $this->members[$key] ?? null;

        return $member instanceof InnerList ? $member : null;
    }

    /**
     * Returns the bare value of the Item for $key, or $default.
     *
     * @template T
     *
     * @param T $default
     *
     * @return BareValue|T
     */
    public function value(string $key, mixed $default = null): mixed
    {
        $member = $this->members[$key] ?? null;

        return $member instanceof Item ? $member->value : $default;
    }

    /**
     * Returns a copy with $key set. An existing key keeps its position.
     *
     * @param Item|InnerList|BareValue $member
     */
    public function with(string $key, Item|InnerList|int|float|string|bool|Token|ByteSequence|Date|DisplayString $member): self
    {
        $members = $this->members;
        $members[$key] = $member instanceof Item || $member instanceof InnerList ? $member : new Item($member);

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
     * Returns a copy with all members of $other added (or overwritten).
     *
     * @param self|iterable<string, Item|InnerList|BareValue> $other
     */
    public function merge(self|iterable $other): self
    {
        $members = $this->members;
        foreach ($other instanceof self ? $other->members : (new self($other))->members as $key => $member) {
            $members[$key] = $member;
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
     * @return array<string, Item|InnerList>
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
     * @return Traversable<string, Item|InnerList>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->members);
    }

    /**
     * Returns the serialized Dictionary. An empty Dictionary serializes to '' —
     * per RFC 9651 the field should then be omitted entirely.
     */
    public function toHttpValue(): string
    {
        $parts = [];
        foreach ($this->members as $key => $member) {
            $parts[] = $member instanceof Item && $member->value === true
                ? $key . $member->parameters->toHttpValue()
                : $key . '=' . $member->toHttpValue();
        }

        return implode(', ', $parts);
    }

    public function __toString(): string
    {
        return $this->toHttpValue();
    }
}
