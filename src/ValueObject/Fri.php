<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\ValueObject;

use Stringable;
use BrilliantMind\Mkesh\Enum\FriType;
use BrilliantMind\Mkesh\Exception\InvalidArgumentException;

/**
 * A Financial Resource Identifier, rendered on the wire as "FRI:<value>/<TYPE>".
 *
 * Examples: FRI:258823040400/MSISDN, FRI:pagamKesh/USER, FRI:1360073/MM
 */
final class Fri implements Stringable
{
    public function __construct(
        public readonly string $value,
        public readonly string $type,
    ) {
        if (trim($value) === '') {
            throw new InvalidArgumentException('FRI value cannot be empty.');
        }
        if (trim($type) === '') {
            throw new InvalidArgumentException('FRI type cannot be empty.');
        }
    }

    /** A mobile subscriber number, e.g. Fri::msisdn('258823040400'). */
    public static function msisdn(string $msisdn): self
    {
        return new self($msisdn, FriType::MSISDN);
    }

    /** A named service-provider user account, e.g. Fri::user('pagamKesh'). */
    public static function user(string $id): self
    {
        return new self($id, FriType::USER);
    }

    /** A mobile money account, e.g. Fri::mobileMoney('1360073'). */
    public static function mobileMoney(string $id): self
    {
        return new self($id, FriType::MOBILE_MONEY);
    }

    /**
     * Parse a string in the canonical "FRI:<value>/<TYPE>" form.
     */
    public static function fromString(string $fri): self
    {
        $raw = trim($fri);

        if (stripos($raw, 'FRI:') !== 0) {
            throw new InvalidArgumentException(sprintf('FRI "%s" must start with "FRI:".', $fri));
        }

        $body = substr($raw, 4);
        $slash = strrpos($body, '/');

        if ($slash === false) {
            throw new InvalidArgumentException(sprintf('FRI "%s" is missing the "/<TYPE>" suffix.', $fri));
        }

        return new self(substr($body, 0, $slash), substr($body, $slash + 1));
    }

    public function __toString(): string
    {
        return sprintf('FRI:%s/%s', $this->value, $this->type);
    }
}
