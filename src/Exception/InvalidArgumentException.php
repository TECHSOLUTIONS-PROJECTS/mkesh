<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\Exception;

/**
 * Thrown when a value object or request is built with invalid input.
 */
class InvalidArgumentException extends \InvalidArgumentException implements MkeshException
{
}
