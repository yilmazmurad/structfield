<?php

declare(strict_types=1);

namespace YilmazMurad\StructField;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;
use YilmazMurad\StructField\Exception\InvalidValueException;

/**
 * An Inner List (RFC 9651, Section 3.1.1): `("foo" "bar");lvl=5`.
 *
 * @phpstan-import-type BareValue from Parameters
 *
 * @implements IteratorAggregate<int, Item>
 */
final readonly class InnerList implements StructuredValue, IteratorAggregate, Countable
{
    /** @var list<Item> */
    public array $items;

    public Parameters $parameters;

    /**
     * @param iterable<Item|BareValue>               $items      Items, or bare values wrapped into Items.
     * @param Parameters|iterable<string, BareValue> $parameters
     *
     * @throws InvalidValueException
     */
    public function __construct(iterable $items = [], Parameters|iterable $parameters = [])
    {
        $list = [];
        foreach ($items as $item) {
            $list[] = $item instanceof Item ? $item : new Item($item);
        }
        $this->items = $list;
        $this->parameters = Parameters::from($parameters);
    }

    public function get(int $index): ?Item
    {
        return $this->items[$index < 0 ? count($this->items) + $index : $index] ?? null;
    }

    /**
     * Returns the bare values of all items (parameters dropped).
     *
     * @return list<BareValue>
     */
    public function values(): array
    {
        return array_map(static fn(Item $item) => $item->value, $this->items);
    }

    /** @param Item|BareValue ...$items */
    public function append(Item|int|float|string|bool|Token|ByteSequence|Date|DisplayString ...$items): self
    {
        return new self([...$this->items, ...array_values($items)], $this->parameters);
    }

    public function remove(int $index): self
    {
        $items = $this->items;
        unset($items[$index]);

        return new self($items, $this->parameters);
    }

    /** @param Parameters|iterable<string, BareValue> $parameters */
    public function withParameters(Parameters|iterable $parameters): self
    {
        return new self($this->items, $parameters);
    }

    /** @param BareValue $value */
    public function withParameter(string $key, int|float|string|bool|Token|ByteSequence|Date|DisplayString $value): self
    {
        return new self($this->items, $this->parameters->with($key, $value));
    }

    public function withoutParameter(string ...$keys): self
    {
        return new self($this->items, $this->parameters->without(...$keys));
    }

    /**
     * Shortcut for $innerList->parameters->get($key, $default).
     *
     * @template T
     *
     * @param T $default
     *
     * @return BareValue|T
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->parameters->get($key, $default);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return Traversable<int, Item>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function toHttpValue(): string
    {
        return '(' . implode(' ', array_map(static fn(Item $i) => $i->toHttpValue(), $this->items)) . ')'
            . $this->parameters->toHttpValue();
    }

    public function __toString(): string
    {
        return $this->toHttpValue();
    }
}
