<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\Exception;

/**
 * Thrown when the SDK is configured with missing or invalid settings.
 */
class ConfigurationException extends \RuntimeException implements MkeshException
{
}
