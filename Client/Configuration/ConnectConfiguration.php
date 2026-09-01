<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Configuration;

/**
 * Configure connection establishment for HTTP clients.
 */
final class ConnectConfiguration
{
    /**
     * @throws \InvalidArgumentException If the connect timeout is invalid.
     */
    public function __construct(
        /** TCP connection timeout in seconds. */
        public readonly float $connectTimeout = 10.0,

        /** HTTP protocol version policy. */
        public readonly HTTPVersionPolicy $versionPolicy =
            HTTPVersionPolicy::Automatic,

        /** TLS configuration. Null uses transport defaults. */
        public readonly ?TLSConfiguration $tlsConfiguration = null,

        /** HTTP/2 configuration. Null uses transport defaults. */
        public readonly ?HTTP2Configuration $h2Configuration = null
    )
    {
        if (!\is_finite($connectTimeout) || $connectTimeout <= 0.0) {
            throw new \InvalidArgumentException(
                'Connect timeout must be finite and positive.'
            );
        }
    }
}
