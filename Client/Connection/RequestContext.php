<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client\Connection;

/**
 * Context for a connection: where it connects to.
 *
 * Captures the target host, port, scheme, and optional proxy
 * so that connection creation and pool keying have a single
 * source of truth.
 */
final class RequestContext
{
    public function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $proxy = null
    ) {}

    /**
     * Create from a PSR-7 URI and optional proxy URL.
     */
    public static function fromUri(
        \Psr\Http\Message\UriInterface $uri,
        ?string $proxy = null
    ): self
    {
        $scheme = $uri->getScheme() ?: 'https';

        return new self(
            scheme: $scheme,
            host: $uri->getHost(),
            port: self::resolvePort($scheme, $uri->getPort()),
            proxy: $proxy
        );
    }

    /**
     * Derive the CacheKey for pool lookups.
     */
    public function cacheKey(): CacheKey
    {
        return new CacheKey(
            target: "{$this->scheme}://{$this->host}:{$this->port}",
            proxy: $this->proxy
        );
    }

    private static function resolvePort(string $scheme, ?int $port): int
    {
        if ($port !== null) {
            return $port;
        }

        return match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => throw new \InvalidArgumentException(
                "URI scheme {$scheme} requires an explicit port."
            )
        };
    }
}
