<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Message;

use Psr\Http\Message\StreamInterface;

/**
 * Shared implementation of PSR-7 MessageInterface methods.
 *
 * Used by both Request and Response. Handles protocol version,
 * headers (case-insensitive lookup, case-preserving storage),
 * and body stream.
 */
trait MessageTrait
{
    private string $protocolVersion = '1.1';

    /**
     * Headers stored as [lowercase name => [original name, [values]]].
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private array $headers = [];

    private StreamInterface $body;

    /**
     * Get the HTTP protocol version.
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * Return a message with the provided protocol version.
     */
    public function withProtocolVersion(string $version): static
    {
        HttpMessageValidator::validateProtocolVersion($version);

        if ($version === $this->protocolVersion) {
            return $this;
        }

        $new = clone $this;
        $new->protocolVersion = $version;

        return $new;
    }

    /**
     * Get all message headers.
     *
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        $result = [];
        foreach ($this->headers as [$name, $values]) {
            $result[$name] = $values;
        }

        return $result;
    }

    /**
     * Get the raw internal headers array for zero-overhead access.
     *
     * Format: [lowercase_name => [OriginalName, [values]]]
     * Used by the protocol layer to avoid per-header normalization.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public function getRawHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Determine whether a header exists.
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[\strtolower($name)]);
    }

    /**
     * Get every value for a header.
     *
     * @return list<string>
     */
    public function getHeader(string $name): array
    {
        $lower = \strtolower($name);
        if (!isset($this->headers[$lower])) {
            return [];
        }

        return $this->headers[$lower][1];
    }

    /**
     * Get a comma-separated header value.
     */
    public function getHeaderLine(string $name): string
    {
        return \implode(', ', $this->getHeader($name));
    }

    /**
     * Return a message with a replaced header.
     */
    public function withHeader(string $name, $value): static
    {
        HttpMessageValidator::validateHeaderName($name);
        $values = HttpMessageValidator::normalizeHeaderValues($value);

        $new = clone $this;
        $lower = \strtolower($name);
        $new->headers[$lower] = [$name, $values];

        return $new;
    }

    /**
     * Return a message with an appended header value.
     */
    public function withAddedHeader(string $name, $value): static
    {
        HttpMessageValidator::validateHeaderName($name);
        $values = HttpMessageValidator::normalizeHeaderValues($value);

        $new = clone $this;
        $lower = \strtolower($name);

        if (isset($new->headers[$lower])) {
            $new->headers[$lower][1] = \array_merge(
                $new->headers[$lower][1],
                $values
            );
        } else {
            $new->headers[$lower] = [$name, $values];
        }

        return $new;
    }

    /**
     * Add trusted normalized headers in a single clone.
     *
     * Existing headers are preserved. Incoming headers overwrite by lowercase
     * key. Input is not validated, lowercased, trimmed, or normalized.
     *
     * @param array<string, array{0: string, 1: list<string>}> $headers
     */
    public function withNormalizedHeaders(array $headers): static
    {
        if ($headers === []) {
            return $this;
        }

        $new = clone $this;

        foreach ($headers as $lower => $header) {
            $new->headers[$lower] = $header;
        }

        return $new;
    }

    /**
     * Replace one trusted normalized header in a single clone.
     *
     * Input is not validated, lowercased, trimmed, or normalized.
     *
     * @param list<string> $values
     */
    public function withNormalizedHeader(
        string $lowerName,
        string $originalName,
        array $values
    ): static {
        $new = clone $this;
        $new->headers[$lowerName] = [$originalName, $values];

        return $new;
    }

    /**
     * Return a message without a header.
     */
    public function withoutHeader(string $name): static
    {
        $lower = \strtolower($name);
        if (!isset($this->headers[$lower])) {
            return $this;
        }

        $new = clone $this;
        unset($new->headers[$lower]);

        return $new;
    }

    /**
     * Get the message body stream.
     */
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    /**
     * Return a message with a replaced body stream.
     */
    public function withBody(StreamInterface $body): static
    {
        $normalized = $this->normalizeBody($body);
        if ($normalized === $this->body) {
            return $this;
        }

        $new = clone $this;
        $new->body = $normalized;

        return $new;
    }

    /**
     * Set headers from an associative array during construction.
     *
     * @param array<string, string|string[]> $headers
     */
    private function setHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            if (!\is_string($name)) {
                throw new \InvalidArgumentException(
                    'HTTP header names must be strings.'
                );
            }

            HttpMessageValidator::validateHeaderName($name);
            $lower = \strtolower($name);
            $values = HttpMessageValidator::normalizeHeaderValues($value);
            $this->headers[$lower] = [$name, $values];
        }
    }

    /**
     * Normalize a body passed to withBody() for this message type.
     */
    abstract private function normalizeBody(
        StreamInterface $body
    ): StreamInterface;
}
