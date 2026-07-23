<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Exception;

/**
 * Thrown when a value object or request is built with invalid input.
 */
class InvalidArgumentException extends \InvalidArgumentException implements MkeshException
{
}
