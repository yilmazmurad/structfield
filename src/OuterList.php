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
 * A List (RFC 9651, Section 3.1): `sugar, tea, rum` or `("foo" "bar"), ("baz")`.
 *
 * Named OuterList because `list` is a reserved word in PHP.
 *
 * @phpstan-import-type BareValue from Parameters
 *
 * @implements IteratorAggregate<int, Item|InnerList>
 */
final readonly class OuterList implements StructuredValue, IteratorAggregate, Countable
{
    /** @var list<Item|InnerList> */
    public array $members;

    /**
     * @param iterable<Item|InnerList|BareValue> $members Bare values are wrapped into Items.
     *
     * @throws InvalidValueException
     */
    public function __construct(iterable $members = [])
    {
        $list = [];
        foreach ($members as $member) {
            $list[] = $member instanceof Item || $member instanceof InnerList ? $member : new Item($member);
        }
        $this->members = $list;
    }

    /**
     * Parses a List field value.
     *
     * @param string|list<string> $httpValue One field value, or several field lines to be combined.
     *
     * @throws ParseException
     */
    public static function fromHttpValue(string|array $httpValue): self
    {
        return Parser::parseList($httpValue);
    }

    /**
     * Returns the member at $index (negative indexes count from the end), or null.
     */
    public function get(int $index): Item|InnerList|null
    {
        return $this->members[$index < 0 ? count($this->members) + $index : $index] ?? null;
    }

    /**
     * Returns the bare values of all Item members (Inner Lists become lists of bare values).
     *
     * @return list<BareValue|list<BareValue>>
     */
    public function values(): array
    {
        return array_map(
            static fn(Item|InnerList $m) => $m instanceof Item ? $m->value : $m->values(),
            $this->members,
        );
    }

    /**
     * Returns true when an Item with the given bare value is a member (e.g. a Token name).
     */
    public function contains(int|float|string|bool|Token|ByteSequence|Date|DisplayString $value): bool
    {
        $needle = Bare::serialize($value);
        foreach ($this->members as $member) {
            if ($member instanceof Item && Bare::serialize($member->value) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** @param Item|InnerList|BareValue ...$members */
    public function append(Item|InnerList|int|float|string|bool|Token|ByteSequence|Date|DisplayString ...$members): self
    {
        return new self([...$this->members, ...array_values($members)]);
    }

    /** @param Item|InnerList|BareValue ...$members */
    public function prepend(Item|InnerList|int|float|string|bool|Token|ByteSequence|Date|DisplayString ...$members): self
    {
        return new self([...array_values($members), ...$this->members]);
    }

    public function remove(int $index): self
    {
        $members = $this->members;
        unset($members[$index]);

        return new self($members);
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
     * @return Traversable<int, Item|InnerList>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->members);
    }

    /**
     * Returns the serialized List. An empty List serializes to '' — per RFC 9651
     * the field should then be omitted entirely.
     */
    public function toHttpValue(): string
    {
        return implode(', ', array_map(static fn(Item|InnerList $m) => $m->toHttpValue(), $this->members));
    }

    public function __toString(): string
    {
        return $this->toHttpValue();
    }
}
