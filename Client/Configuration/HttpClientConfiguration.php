<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Configuration;

use Smithy\Http\Client\HttpRequestOptions;

/**
 * Group HTTP transport configuration values.
 */
final class HttpClientConfiguration
{
    public function __construct(
        /** Connection pool sizing and health policy. */
        public readonly ?ConnectionManagerConfiguration
            $connectionManagerConfig = null,

        /** TCP/TLS connection establishment settings. */
        public readonly ?ConnectConfiguration $connectConfig = null,

        /** Per-request transport defaults. */
        public readonly ?HttpRequestOptions $defaultOptions = null
    ) {}
}
