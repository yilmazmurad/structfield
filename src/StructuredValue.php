<?php

declare(strict_types=1);

namespace YilmazMurad\StructField;

use Stringable;

/**
 * A value that can be serialized to its canonical Structured Field text.
 */
interface StructuredValue extends Stringable
{
    /**
     * Returns the canonical serialization (RFC 9651, Section 4.1).
     */
    public function toHttpValue(): string;
}
