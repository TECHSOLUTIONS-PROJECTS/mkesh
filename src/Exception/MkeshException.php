<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\Exception;

use Throwable;

/**
 * Base interface implemented by every exception thrown by this SDK, so callers
 * can catch all MKESH failures with a single catch block.
 */
interface MkeshException extends Throwable
{
}
