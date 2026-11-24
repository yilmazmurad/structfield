<?php

declare(strict_types=1);

namespace YilmazMurad\StructField;

use Stringable;
use YilmazMurad\StructField\Exception\InvalidValueException;

/**
 * A Display String (RFC 9651, Section 3.3.8): Unicode text, serialized as `%"f%c3%bc%c3%bc"`.
 */
final readonly class DisplayString implements Stringable
{
    public string $value;

    /**
     * @param string $value A valid UTF-8 string.
     */
    public function __construct(string $value)
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidValueException('Display String must be valid UTF-8.');
        }
        $this->value = $value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
