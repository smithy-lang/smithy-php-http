<?php

declare(strict_types=1);

namespace Smithy\Http\Message;

/**
 * Validate values stored in HTTP messages.
 *
 * @internal
 */
final class HttpMessageValidator
{
    private const TOKEN_PATTERN =
        "/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/D";
    private const PROTOCOL_VERSION_PATTERN =
        '/^[0-9]+(?:\.[0-9]+)?$/D';
    private const INVALID_FIELD_VALUE_PATTERN =
        '/[\x00-\x08\x0A-\x1F\x7F]/';

    /**
     * Validate an HTTP method.
     *
     * @return void
     * @throws \InvalidArgumentException If the method is not an HTTP token.
     */
    public static function validateMethod(string $method): void
    {
        if (\preg_match(self::TOKEN_PATTERN, $method) !== 1) {
            throw new \InvalidArgumentException(
                'HTTP methods must be valid tokens.'
            );
        }
    }

    /**
     * Validate an HTTP protocol version.
     *
     * @return void
     * @throws \InvalidArgumentException If the version is not numeric.
     */
    public static function validateProtocolVersion(string $version): void
    {
        if (\preg_match(
            self::PROTOCOL_VERSION_PATTERN,
            $version
        ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'HTTP protocol versions must contain only a version number.'
            );
        }
    }

    /**
     * Validate an HTTP status code.
     *
     * @return void
     * @throws \InvalidArgumentException If the code is outside 100 through 599.
     */
    public static function validateStatusCode(int $statusCode): void
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new \InvalidArgumentException(
                'HTTP status codes must be between 100 and 599.'
            );
        }
    }

    /**
     * Validate an HTTP reason phrase.
     *
     * @return void
     * @throws \InvalidArgumentException If the phrase contains control bytes.
     */
    public static function validateReasonPhrase(string $reasonPhrase): void
    {
        if (\preg_match(
            self::INVALID_FIELD_VALUE_PATTERN,
            $reasonPhrase
        ) === 1
        ) {
            throw new \InvalidArgumentException(
                'HTTP reason phrases contain invalid control bytes.'
            );
        }
    }

    /**
     * Validate an HTTP header name.
     *
     * @return void
     * @throws \InvalidArgumentException If the name is not an HTTP token.
     */
    public static function validateHeaderName(string $name): void
    {
        if (\preg_match(self::TOKEN_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(
                'HTTP header names must be valid tokens.'
            );
        }
    }

    /**
     * Normalize HTTP header values.
     *
     * @param mixed $value Header value or array of header values.
     *
     * @return list<string>
     * @throws \InvalidArgumentException If a value is not a valid string.
     */
    public static function normalizeHeaderValues(mixed $value): array
    {
        if (!\is_string($value) && !\is_array($value)) {
            throw new \InvalidArgumentException(
                'HTTP header values must be strings or arrays of strings.'
            );
        }

        $values = \is_array($value) ? $value : [$value];
        $normalized = [];

        foreach ($values as $fieldValue) {
            if (!\is_string($fieldValue)) {
                throw new \InvalidArgumentException(
                    'HTTP header values must be strings.'
                );
            }

            if (\preg_match(
                self::INVALID_FIELD_VALUE_PATTERN,
                $fieldValue
            ) === 1
            ) {
                throw new \InvalidArgumentException(
                    'HTTP header values contain invalid control bytes.'
                );
            }

            $normalized[] = \trim($fieldValue, " \t");
        }

        return $normalized;
    }
}
