<?php

declare(strict_types=1);

namespace YilmazMurad\StructField\Exception;

use UnexpectedValueException;

/**
 * Thrown by typed accessors (e.g. Item::asInt()) when the value has a different type.
 */
final class TypeMismatchException extends UnexpectedValueException implements StructFieldException {}
