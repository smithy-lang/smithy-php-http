<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client\Curl;

use Smithy\Http\Client\Configuration\ConnectConfiguration;
use Smithy\Http\Client\Configuration\HTTPVersionPolicy;
use Smithy\Http\Client\Connection\ConnectionFactoryInterface;
use Smithy\Http\Client\Connection\HttpConnectionInterface;
use Smithy\Http\Client\Connection\HttpVersion;
use Smithy\Http\Client\Connection\RequestContext;

/**
 * Create new CurlConnection instances.
 *
 * Encapsulates cURL handle creation and connection-level option application.
 *
 * @internal
 */
final class CurlConnectionFactory implements ConnectionFactoryInterface
{
    /**
     * Create a cURL connection.
     */
    public function create(
        RequestContext $requestContext,
        ConnectConfiguration $config
    ): HttpConnectionInterface
    {
        $handle = \curl_init();
        $now = \microtime(true);

        self::applyConnectConfiguration(
            $handle,
            $config,
            $requestContext->proxy
        );
        $connection = new CurlConnection(
            handle: $handle,
            requestContext: $requestContext,
            httpVersion: $this->resolveHttpVersion($config->versionPolicy),
            createdAt: $now
        );

        return $connection;
    }

    /**
     * Apply connection-level settings to the cURL handle.
     *
     * These are connection establishment settings, NOT per-request
     * options. Per-request options (URL, method, headers, body) are
     * applied by CurlClient.
     *
     * @return void
     */
    public static function applyConnectConfiguration(
        \CurlHandle $handle,
        ConnectConfiguration $config,
        ?string $proxy = null
    ): void
    {
        // Connect timeout.
        \curl_setopt(
            $handle,
            CURLOPT_CONNECTTIMEOUT_MS,
            self::secondsToCurlMilliseconds($config->connectTimeout)
        );
        \curl_setopt_array($handle, [
            CURLOPT_TCP_KEEPALIVE => true,
            CURLOPT_TCP_KEEPIDLE => 30,
            CURLOPT_TCP_KEEPINTVL => 15
        ]);

        self::applyHttpVersionPolicy($handle, $config->versionPolicy);
        self::applyMinimumTlsVersion($handle);

        // TLS configuration.
        if ($config->tlsConfiguration !== null) {
            $tls = $config->tlsConfiguration;

            \curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, $tls->verifyPeer);
            \curl_setopt(
                $handle,
                CURLOPT_SSL_VERIFYHOST,
                $tls->verifyPeer ? 2 : 0
            );

            if ($tls->certificate !== null) {
                \curl_setopt($handle, CURLOPT_SSLCERT, $tls->certificate);
            }

            if ($tls->privateKey !== null) {
                \curl_setopt($handle, CURLOPT_SSLKEY, $tls->privateKey);
            }

            if ($tls->caFile !== null) {
                \curl_setopt($handle, CURLOPT_CAINFO, $tls->caFile);
            }

            if ($tls->caDir !== null) {
                \curl_setopt($handle, CURLOPT_CAPATH, $tls->caDir);
            }

            if ($tls->cipherSuites !== []) {
                self::applyTlsCipherSuites(
                    $handle,
                    $tls->cipherSuites,
                    self::usesHttpsProxy($proxy)
                );
            }

            if ($tls->tls13CipherSuites !== []) {
                self::applyTls13CipherSuites(
                    $handle,
                    $tls->tls13CipherSuites,
                    self::usesHttpsProxy($proxy)
                );
            }
        }
    }

    /**
     * Apply the requested HTTP version policy.
     */
    private static function applyHttpVersionPolicy(
        \CurlHandle $handle,
        HTTPVersionPolicy $policy
    ): void
    {
        if (!\curl_setopt(
                $handle,
                CURLOPT_HTTP_VERSION,
                self::resolveCurlHttpVersion($policy)
            )
        ) {
            throw new \RuntimeException(
                'Unable to apply the configured HTTP version policy.'
            );
        }
    }

    /**
     * Resolve the cURL HTTP version option for a policy.
     */
    private static function resolveCurlHttpVersion(
        HTTPVersionPolicy $policy
    ): int
    {
        return match ($policy) {
            HTTPVersionPolicy::EnforceHTTP_1_1 => self::curlConstant(
                'CURL_HTTP_VERSION_1_1',
                'Enforcing HTTP/1.1 requires cURL HTTP/1.1 support.'
            ),
            HTTPVersionPolicy::EnforceHTTP_2 => self::curlConstant(
                'CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE',
                'Enforcing HTTP/2 requires cURL HTTP/2 support.'
            ),
            HTTPVersionPolicy::Automatic => \defined('CURL_HTTP_VERSION_2TLS')
                ? (int) \constant('CURL_HTTP_VERSION_2TLS')
                : self::curlConstant(
                    'CURL_HTTP_VERSION_1_1',
                    'Automatic HTTP negotiation requires cURL HTTP support.'
                )
        };
    }

    /**
     * Apply the default minimum TLS version.
     */
    private static function applyMinimumTlsVersion(\CurlHandle $handle): void
    {
        if (!\defined('CURL_SSLVERSION_TLSv1_2')) {
            throw new \RuntimeException(
                'TLS 1.2 or newer support is required.'
            );
        }

        $tls12 = (int) \constant('CURL_SSLVERSION_TLSv1_2');
        if (!\curl_setopt($handle, CURLOPT_SSLVERSION, $tls12)) {
            throw new \RuntimeException(
                'Unable to apply the minimum TLS version.'
            );
        }

        if (\defined('CURLOPT_PROXY_SSLVERSION')
            && !\curl_setopt(
                $handle,
                (int) \constant('CURLOPT_PROXY_SSLVERSION'),
                $tls12
            )
        ) {
            throw new \RuntimeException(
                'Unable to apply the minimum proxy TLS version.'
            );
        }
    }

    /**
     * Apply configured TLS 1.2 cipher suites.
     *
     * @param list<string> $cipherSuites
     */
    private static function applyTlsCipherSuites(
        \CurlHandle $handle,
        array $cipherSuites,
        bool $usesHttpsProxy
    ): void
    {
        if (!\defined('CURLOPT_SSL_CIPHER_LIST')) {
            throw new \InvalidArgumentException(
                'TLS 1.2 cipher suites require cURL cipher option support.'
            );
        }

        $cipherList = \implode(':', $cipherSuites);
        if (!\curl_setopt(
                $handle,
                (int) \constant('CURLOPT_SSL_CIPHER_LIST'),
                $cipherList
            )
        ) {
            throw new \InvalidArgumentException(
                'Unable to apply the configured TLS 1.2 cipher suites.'
            );
        }

        if (!$usesHttpsProxy) {
            return;
        }

        if (!\defined('CURLOPT_PROXY_SSL_CIPHER_LIST')) {
            throw new \InvalidArgumentException(
                'TLS 1.2 proxy cipher suites require cURL proxy '
                    . 'cipher option support.'
            );
        }

        if (!\curl_setopt(
                $handle,
                (int) \constant('CURLOPT_PROXY_SSL_CIPHER_LIST'),
                $cipherList
            )
        ) {
            throw new \InvalidArgumentException(
                'Unable to apply the configured TLS 1.2 proxy cipher suites.'
            );
        }
    }

    /**
     * Apply configured TLS 1.3 cipher suites.
     *
     * @param list<string> $cipherSuites
     */
    private static function applyTls13CipherSuites(
        \CurlHandle $handle,
        array $cipherSuites,
        bool $usesHttpsProxy
    ): void
    {
        if (!\defined('CURLOPT_TLS13_CIPHERS')) {
            throw new \InvalidArgumentException(
                'TLS 1.3 cipher suites require cURL TLS 1.3 '
                    . 'cipher option support.'
            );
        }

        $cipherList = \implode(':', $cipherSuites);
        if (!\curl_setopt(
                $handle,
                (int) \constant('CURLOPT_TLS13_CIPHERS'),
                $cipherList
            )
        ) {
            throw new \InvalidArgumentException(
                'Unable to apply the configured TLS 1.3 cipher suites.'
            );
        }

        if (!$usesHttpsProxy) {
            return;
        }

        if (!\defined('CURLOPT_PROXY_TLS13_CIPHERS')) {
            throw new \InvalidArgumentException(
                'TLS 1.3 proxy cipher suites require cURL TLS 1.3 '
                    . 'proxy cipher option support.'
            );
        }

        if (!\curl_setopt(
                $handle,
                (int) \constant('CURLOPT_PROXY_TLS13_CIPHERS'),
                $cipherList
            )
        ) {
            throw new \InvalidArgumentException(
                'Unable to apply the configured TLS 1.3 proxy cipher suites.'
            );
        }
    }

    /**
     * Determine whether the configured proxy uses TLS.
     */
    private static function usesHttpsProxy(?string $proxy): bool
    {
        if ($proxy === null) {
            return false;
        }

        $scheme = \parse_url($proxy, PHP_URL_SCHEME);

        return \is_string($scheme) && \strtolower($scheme) === 'https';
    }

    /**
     * Get a cURL constant value or fail with a clear message.
     */
    private static function curlConstant(string $name, string $message): int
    {
        if (!\defined($name)) {
            throw new \RuntimeException($message);
        }

        return (int) \constant($name);
    }

    /**
     * Convert positive seconds to cURL milliseconds.
     */
    private static function secondsToCurlMilliseconds(float $seconds): int
    {
        return \max(1, (int) \ceil($seconds * 1000));
    }

    /**
     * Map HTTPVersionPolicy to the expected negotiated version.
     *
     * Automatic mode starts with HTTP/1.1. TLS negotiation records the
     * final protocol after the handshake.
     */
    private function resolveHttpVersion(HTTPVersionPolicy $policy): HttpVersion
    {
        return match ($policy) {
            HTTPVersionPolicy::EnforceHTTP_2 => HttpVersion::HTTP_2,
            default => HttpVersion::HTTP_1_1
        };
    }
}
