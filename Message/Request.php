<?php

declare(strict_types=1);

namespace Smithy\Http\Message;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-7 RequestInterface implementation.
 *
 * Immutable HTTP request. All with*() methods return a new instance.
 * Per PSR-7 spec, the Host header is set from the URI during
 * construction if not explicitly provided.
 */
final class Request implements RequestInterface
{
    use MessageTrait;

    private string $method;
    private UriInterface $uri;
    private ?string $requestTarget = null;

    /**
     * Create an HTTP request.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string|UriInterface $uri Request URI
     * @param array<string, string|string[]> $headers Headers in external form.
     * @param string|StreamInterface|null $body Request body
     * @param string $protocolVersion HTTP protocol version
     * @param bool $normalizeHeaders Whether to validate, trim, and normalize
     *     headers into the internal representation. Pass false only when
     *     headers already use the trusted normalized format, such as
     *     `['content-type' => ['Content-Type', ['application/cbor']]]`.
     *
     * @throws \InvalidArgumentException If message metadata is invalid.
     */
    public function __construct(
        string $method,
        string|UriInterface $uri,
        array $headers = [],
        string|StreamInterface|null $body = null,
        string $protocolVersion = '1.1',
        bool $normalizeHeaders = true
    ) {
        HttpMessageValidator::validateMethod($method);
        HttpMessageValidator::validateProtocolVersion($protocolVersion);
        $this->method = $method;
        $this->uri = \is_string($uri) ? new Uri($uri) : $uri;
        $this->protocolVersion = $protocolVersion;

        if ($headers !== []) {
            $normalizeHeaders
                ? $this->setHeaders($headers)
                : $this->headers = $headers;
        }

        // Accept streams directly and wrap strings in StringRequestStream.
        if ($body instanceof StreamInterface) {
            $this->body = StreamBodyAdapter::wrap($body);
        } elseif (\is_string($body) && $body !== '') {
            $this->body = new StringRequestStream($body);
        } else {
            $this->body = StringRequestStream::empty();
        }

        // PSR-7: Set Host header from URI if not provided.
        if (!isset($this->headers['host']) && $this->uri->getHost() !== '') {
            $this->updateHostFromUri();
        }
    }

    /**
     * Create a request from trusted normalized headers.
     *
     * Skips header validation and normalization.
     *
     * @param array<string, array{0: string, 1: list<string>}> $headers
     */
    public static function fromNormalized(
        string $method,
        string|UriInterface $uri,
        array $headers = [],
        string|StreamInterface|null $body = null,
        string $protocolVersion = '1.1'
    ): self {
        return new self(
            $method,
            $uri,
            $headers,
            $body,
            $protocolVersion,
            normalizeHeaders: false
        );
    }

    /**
     * Get the request target.
     */
    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath();
        if ($target === '') {
            $target = '/';
        }

        $query = $this->uri->getQuery();
        if ($query !== '') {
            $target .= '?' . $query;
        }

        return $target;
    }

    /**
     * Return a request with an explicit request target.
     */
    public function withRequestTarget(string $requestTarget): static
    {
        $new = clone $this;
        $new->requestTarget = $requestTarget;

        return $new;
    }

    /**
     * Get the HTTP method.
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Return a request with a replaced HTTP method.
     */
    public function withMethod(string $method): static
    {
        HttpMessageValidator::validateMethod($method);

        if ($method === $this->method) {
            return $this;
        }

        $new = clone $this;
        $new->method = $method;

        return $new;
    }

    /**
     * Get the request URI.
     */
    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    /**
     * Return a request with a replaced URI.
     */
    public function withUri(
        UriInterface $uri,
        bool $preserveHost = false
    ): static
    {
        $new = clone $this;
        $new->uri = $uri;

        if (!$preserveHost || !$this->hasHeader('Host')) {
            if ($uri->getHost() !== '') {
                $new->updateHostFromUri();
            }
        }

        return $new;
    }

    /**
     * Set the Host header from the URI's host component.
     */
    private function updateHostFromUri(): void
    {
        $host = $this->uri->getHost();
        if ($host === '') {
            return;
        }

        $port = $this->uri->getPort();
        if ($port !== null) {
            $host .= ':' . $port;
        }

        $values = HttpMessageValidator::normalizeHeaderValues($host);

        // Host header must be first per PSR-7 convention.
        $this->headers = ['host' => ['Host', $values]] + $this->headers;
    }
}
