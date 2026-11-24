<?php

declare(strict_types=1);

namespace YilmazMurad\StructField;

use Stringable;
use YilmazMurad\StructField\Exception\InvalidValueException;

/**
 * A Token (RFC 9651, Section 3.3.4): a short textual word such as `gzip` or `text/html`.
 */
final readonly class Token implements Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        if (preg_match('/^[A-Za-z*][!#$%&\'*+\-.^_`|~0-9A-Za-z:\/]*$/D', $value) !== 1) {
            throw new InvalidValueException(sprintf('Invalid token "%s".', addcslashes($value, "\0..\37\177..\377")));
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
