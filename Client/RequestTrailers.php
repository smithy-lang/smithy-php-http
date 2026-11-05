<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client;

/**
 * Resolve request trailer fields after the request body is consumed.
 */
final class RequestTrailers
{
    private const DISALLOWED_FIELDS = [
        'authorization' => true,
        'content-encoding' => true,
        'content-length' => true,
        'content-range' => true,
        'content-type' => true,
        'cookie' => true,
        'expect' => true,
        'host' => true,
        'pragma' => true,
        'proxy-authenticate' => true,
        'proxy-authorization' => true,
        'range' => true,
        'te' => true,
        'trailer' => true,
        'transfer-encoding' => true,
        'www-authenticate' => true
    ];

    /** @var \Closure(): array<string, string|list<string>> */
    private readonly \Closure $resolver;
    /** @var (\Closure(): void)|null */
    private readonly ?\Closure $retryReset;

    /**
     * Create a lazy request trailer provider.
     *
     * @param \Closure(): array<string, string|list<string>> $resolver
     * @param int|null $fieldSectionLength Encoded field section length.
     * @param (\Closure(): void)|null $retryReset
     */
    public function __construct(
        \Closure $resolver,
        private readonly ?int $fieldSectionLength = null,
        ?\Closure $retryReset = null
    )
    {
        if ($fieldSectionLength !== null && $fieldSectionLength < 0) {
            throw new \InvalidArgumentException(
                'Trailer field section length must not be negative.'
            );
        }

        $this->resolver = $resolver;
        $this->retryReset = $retryReset;
    }

    /**
     * Create trailers from fields known before transmission.
     *
     * @param array<string, string|list<string>> $fields
     */
    public static function fromArray(array $fields): self
    {
        $fields = self::normalize($fields);
        $length = self::calculateFieldSectionLength($fields);

        return new self(
            static fn(): array => $fields,
            $length
        );
    }

    /**
     * Resolve and validate trailer fields.
     *
     * @return array<string, list<string>>
     */
    public function resolve(): array
    {
        $fields = self::normalize(($this->resolver)());

        if ($this->fieldSectionLength !== null
            && self::calculateFieldSectionLength($fields)
                !== $this->fieldSectionLength
        ) {
            throw new \RuntimeException(
                'Resolved trailer fields do not match the declared length.'
            );
        }

        return $fields;
    }

    /**
     * Get the encoded field-section length when known.
     */
    public function fieldSectionLength(): ?int
    {
        return $this->fieldSectionLength;
    }

    /**
     * Reset provider state before retrying the request.
     *
     * @return void
     */
    public function rewindForRetry(): void
    {
        if ($this->retryReset !== null) {
            ($this->retryReset)();
        }
    }

    /**
     * Normalize and validate trailer fields.
     *
     * @param array<string, string|list<string>> $fields
     *
     * @return array<string, list<string>>
     */
    private static function normalize(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $name => $value) {
            if (!\is_string($name)
                || \preg_match(
                    "/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/D",
                    $name
                ) !== 1
            ) {
                throw new \InvalidArgumentException(
                    'Trailer field names must be valid HTTP tokens.'
                );
            }

            $lowerName = \strtolower($name);
            if (isset(self::DISALLOWED_FIELDS[$lowerName])) {
                throw new \InvalidArgumentException(
                    "Trailer field '{$name}' is not allowed."
                );
            }

            $values = \is_array($value) ? $value : [$value];
            if ($values === []) {
                throw new \InvalidArgumentException(
                    "Trailer field '{$name}' must have at least one value."
                );
            }

            foreach ($values as $fieldValue) {
                if (!\is_string($fieldValue)) {
                    throw new \InvalidArgumentException(
                        "Trailer field '{$name}' values must be strings."
                    );
                }

                if (\preg_match(
                        '/[\x00-\x08\x0A-\x1F\x7F]/',
                        $fieldValue
                    ) === 1
                ) {
                    throw new \InvalidArgumentException(
                        "Trailer field '{$name}' contains invalid bytes."
                    );
                }

                $normalized[$lowerName][] = $fieldValue;
            }
        }

        return $normalized;
    }

    /**
     * Calculate the encoded trailer field-section length.
     *
     * @param array<string, list<string>> $fields
     */
    private static function calculateFieldSectionLength(array $fields): int
    {
        $length = 0;

        foreach ($fields as $name => $values) {
            foreach ($values as $value) {
                $length += \strlen($name) + \strlen($value) + 3;
            }
        }

        return $length;
    }
}
