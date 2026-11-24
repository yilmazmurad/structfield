<?php

declare(strict_types=1);

namespace YilmazMurad\StructField\Exception;

use InvalidArgumentException;

/**
 * Thrown when constructing a value that cannot be represented as a
 * Structured Field (e.g. an out-of-range Integer, a String containing
 * control characters, or an invalid Key).
 */
final class InvalidValueException extends InvalidArgumentException implements StructFieldException {}
