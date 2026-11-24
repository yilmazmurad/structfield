<?php

declare(strict_types=1);

namespace YilmazMurad\StructField;

use DateTimeImmutable;
use DateTimeInterface;
use YilmazMurad\StructField\Exception\InvalidValueException;
use YilmazMurad\StructField\Exception\ParseException;
use YilmazMurad\StructField\Exception\TypeMismatchException;

/**
 * An Item (RFC 9651, Section 3.3): a Bare Item plus Parameters.
 *
 * The bare value is exposed as a native PHP value where possible:
 *
 *  - Integer        → int
 *  - Decimal        → float
 *  - String         → string
 *  - Boolean        → bool
 *  - Token          → Token
 *  - Byte Sequence  → ByteSequence
 *  - Date           → Date
 *  - Display String → DisplayString
 *
 * @phpstan-import-type BareValue from Parameters
 */
final readonly class Item implements StructuredValue
{
    public const int MAX_INTEGER = 999_999_999_999_999;

    /** @var BareValue */
    public int|float|string|bool|Token|ByteSequence|Date|DisplayString $value;

    public Parameters $parameters;

    /**
     * @param BareValue                             $value
     * @param Parameters|iterable<string, BareValue> $parameters
     *
     * @throws InvalidValueException
     */
    public function __construct(
        int|float|string|bool|Token|ByteSequence|Date|DisplayString $value,
        Parameters|iterable $parameters = [],
    ) {
        Bare::validate($value);
        $this->value = $value;
        $this->parameters = Parameters::from($parameters);
    }

    /**
     * Parses an Item field value.
     *
     * @param string|list<string> $httpValue One field value, or several field lines to be combined.
     *
     * @throws ParseException
     */
    public static function fromHttpValue(string|array $httpValue): self
    {
        return Parser::parseItem($httpValue);
    }

    // ---- named constructors -------------------------------------------------

    /** @param Parameters|iterable<string, BareValue> $parameters */
    public static function integer(int $value, Parameters|iterable $parameters = []): self
    {
        return new self($value, $parameters);
    }

    /** @param Parameters|iterable<string, BareValue> $parameters */
    public static function decimal(int|float $value, Parameters|iterable $parameters = []): self
    {
        return new self((float) $value, $parameters);
    }

    /** @param Parameters|iterable<string, BareValue> $parameters */
    public static function string(string $value, Parameters|iterable $parameters = []): self
    {
        return new self($value, $parameters);
    }

    /** @param Parameters|iterable<string, BareValue> $parameters */
    public static function token(string $value, Parameters|iterable $parameters = []): self
    {
        return new self(new Token($value), $parameters);
    }

    /**
     * @param string                                 $bytes      Raw bytes (not base64).
     * @param Parameters|iterable<string, BareValue> $parameters
     */
    public static function bytes(string $bytes, Parameters|iterable $parameters = []): self
    {
        return new self(new ByteSequence($bytes), $parameters);
    }

    /** @param Parameters|iterable<string, BareValue> $parameters */
    public static function boolean(bool $value, Parameters|iterable $parameters = []): self
    {
        return new self($value, $parameters);
    }

    /** @param Parameters|iterable<string, BareValue> $parameters */
    public static function date(int|DateTimeInterface $value, Parameters|iterable $parameters = []): self
    {
        return new self($value instanceof DateTimeInterface ? Date::fromDateTime($value) : new Date($value), $parameters);
    }

    /** @param Parameters|iterable<string, BareValue> $parameters */
    public static function displayString(string $value, Parameters|iterable $parameters = []): self
    {
        return new self(new DisplayString($value), $parameters);
    }

    // ---- inspection ----------------------------------------------------------

    public function type(): BareType
    {
        return BareType::of($this->value);
    }

    public function is(BareType $type): bool
    {
        return $this->type() === $type;
    }

    /**
     * Shortcut for $item->parameters->get($key, $default).
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

    // ---- typed accessors -----------------------------------------------------

    public function asInt(): int
    {
        return is_int($this->value) ? $this->value : throw $this->mismatch(BareType::Integer);
    }

    /**
     * Returns a Decimal, or an Integer converted to float.
     */
    public function asFloat(): float
    {
        return is_float($this->value) || is_int($this->value)
            ? (float) $this->value
            : throw $this->mismatch(BareType::Decimal);
    }

    public function asString(): string
    {
        return is_string($this->value) ? $this->value : throw $this->mismatch(BareType::String);
    }

    /**
     * Returns the Token's text.
     */
    public function asToken(): string
    {
        return $this->value instanceof Token ? $this->value->value : throw $this->mismatch(BareType::Token);
    }

    /**
     * Returns the decoded bytes of a Byte Sequence.
     */
    public function asBytes(): string
    {
        return $this->value instanceof ByteSequence ? $this->value->bytes : throw $this->mismatch(BareType::ByteSequence);
    }

    public function asBool(): bool
    {
        return is_bool($this->value) ? $this->value : throw $this->mismatch(BareType::Boolean);
    }

    public function asDate(): DateTimeImmutable
    {
        return $this->value instanceof Date ? $this->value->toDateTime() : throw $this->mismatch(BareType::Date);
    }

    /**
     * Returns the Unicode text of a Display String.
     */
    public function asDisplayString(): string
    {
        return $this->value instanceof DisplayString ? $this->value->value : throw $this->mismatch(BareType::DisplayString);
    }

    /**
     * Returns the text of a String, Token or Display String.
     */
    public function asText(): string
    {
        return match (true) {
            is_string($this->value) => $this->value,
            $this->value instanceof Token, $this->value instanceof DisplayString => $this->value->value,
            default => throw $this->mismatch(BareType::String),
        };
    }

    // ---- fluent (immutable) modifiers --------------------------------------

    /** @param BareValue $value */
    public function withValue(int|float|string|bool|Token|ByteSequence|Date|DisplayString $value): self
    {
        return new self($value, $this->parameters);
    }

    /** @param Parameters|iterable<string, BareValue> $parameters */
    public function withParameters(Parameters|iterable $parameters): self
    {
        return new self($this->value, $parameters);
    }

    /** @param BareValue $value */
    public function withParameter(string $key, int|float|string|bool|Token|ByteSequence|Date|DisplayString $value): self
    {
        return new self($this->value, $this->parameters->with($key, $value));
    }

    public function withoutParameter(string ...$keys): self
    {
        return new self($this->value, $this->parameters->without(...$keys));
    }

    public function equals(self $other): bool
    {
        return $this->toHttpValue() === $other->toHttpValue();
    }

    public function toHttpValue(): string
    {
        return Bare::serialize($this->value) . $this->parameters->toHttpValue();
    }

    public function __toString(): string
    {
        return $this->toHttpValue();
    }

    private function mismatch(BareType $expected): TypeMismatchException
    {
        return new TypeMismatchException(sprintf('Expected %s, got %s.', $expected->value, $this->type()->value));
    }
}
