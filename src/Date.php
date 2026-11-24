<?php

declare(strict_types=1);

namespace YilmazMurad\StructField;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use YilmazMurad\StructField\Exception\InvalidValueException;

/**
 * A Date (RFC 9651, Section 3.3.7): seconds since the Unix epoch, serialized as `@1659578233`.
 */
final readonly class Date
{
    public int $timestamp;

    public function __construct(int $timestamp)
    {
        if ($timestamp > Item::MAX_INTEGER || $timestamp < -Item::MAX_INTEGER) {
            throw new InvalidValueException(sprintf('Date %d is out of range.', $timestamp));
        }
        $this->timestamp = $timestamp;
    }

    public static function fromDateTime(DateTimeInterface $dateTime): self
    {
        return new self($dateTime->getTimestamp());
    }

    public static function now(): self
    {
        return new self(time());
    }

    public function toDateTime(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    public function equals(self $other): bool
    {
        return $this->timestamp === $other->timestamp;
    }
}
