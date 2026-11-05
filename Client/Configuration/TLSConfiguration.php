<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client\Configuration;

/**
 * TLS configuration settings for HTTP connections.
 *
 * TLS 1.2 is the minimum supported protocol version.
 * Maps to cURL CURLOPT_* options in the transport implementation.
 */
final class TLSConfiguration
{
    /**
     * Empty cipher suite lists use the TLS backend defaults.
     *
     * @param list<string> $cipherSuites TLS 1.2 cipher suite names to allow.
     * @param list<string> $tls13CipherSuites TLS 1.3 cipher suite names to
     *                                        allow.
     *
     * @throws \InvalidArgumentException If a cipher suite name is invalid.
     */
    public function __construct(
        public readonly bool $verifyPeer = true,
        public readonly ?string $certificate = null,
        public readonly ?string $privateKey = null,
        public readonly ?string $caFile = null,
        public readonly ?string $caDir = null,
        public readonly array $cipherSuites = [],
        public readonly array $tls13CipherSuites = []
    )
    {
        foreach ([...$cipherSuites, ...$tls13CipherSuites] as $cipherSuite) {
            if (!\is_string($cipherSuite)
                || !\preg_match(
                    '/\A[A-Za-z0-9_-]+\z/',
                    $cipherSuite
                )
            ) {
                throw new \InvalidArgumentException(
                    'Cipher suites must contain only valid '
                        . 'cipher suite names.'
                );
            }
        }
    }
}
