<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Message;

/**
 * Hold response trailer fields and their transport lifecycle.
 */
final class ResponseTrailers
{
    /** @var array<string, array{0: string, 1: list<string>}> */
    private array $headers = [];
    private bool $complete;
    private ?\Throwable $failure = null;

    private function __construct(bool $complete)
    {
        $this->complete = $complete;
    }

    /**
     * Create pending response trailers.
     *
     * @internal
     */
    public static function pending(): self
    {
        return new self(false);
    }

    /**
     * Create completed response trailers.
     *
     * @param array<string, string|list<string>> $headers
     */
    public static function completed(array $headers = []): self
    {
        $trailers = new self(false);
        $trailers->complete($headers);

        return $trailers;
    }

    /**
     * Determine whether the trailer state reached completion or failure.
     */
    public function isComplete(): bool
    {
        return $this->complete || $this->failure !== null;
    }

    /**
     * Get all trailer fields.
     *
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        $this->throwIfUnavailable();
        $result = [];

        foreach ($this->headers as [$name, $values]) {
            $result[$name] = $values;
        }

        return $result;
    }

    /**
     * Determine whether a trailer field exists.
     */
    public function hasHeader(string $name): bool
    {
        $this->throwIfUnavailable();

        return isset($this->headers[\strtolower($name)]);
    }

    /**
     * Get every value for a trailer field.
     *
     * @return list<string>
     */
    public function getHeader(string $name): array
    {
        $this->throwIfUnavailable();

        return $this->headers[\strtolower($name)][1] ?? [];
    }

    /**
     * Get a comma-separated trailer field value.
     */
    public function getHeaderLine(string $name): string
    {
        return \implode(', ', $this->getHeader($name));
    }

    /**
     * Complete the trailer state.
     *
     * @param array<string, string|list<string>> $headers
     *
     * @return void
     * @internal
     */
    public function complete(array $headers): void
    {
        if ($this->complete || $this->failure !== null) {
            return;
        }

        foreach ($headers as $name => $values) {
            $lowerName = \strtolower($name);
            $values = \is_array($values) ? $values : [$values];

            if (isset($this->headers[$lowerName])) {
                $this->headers[$lowerName][1] = \array_merge(
                    $this->headers[$lowerName][1],
                    $values
                );

                continue;
            }

            $this->headers[$lowerName] = [$name, $values];
        }

        $this->complete = true;
    }

    /**
     * Fail the trailer state with its transport error.
     *
     * @return void
     * @internal
     */
    public function fail(\Throwable $failure): void
    {
        if ($this->complete || $this->failure !== null) {
            return;
        }

        $this->failure = $failure;
    }

    /**
     * Reject access before trailers reach a terminal state.
     *
     * @return void
     */
    private function throwIfUnavailable(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        if (!$this->complete) {
            throw new \LogicException(
                'Response trailers are unavailable until the response '
                    . 'body completes.'
            );
        }
    }
}
