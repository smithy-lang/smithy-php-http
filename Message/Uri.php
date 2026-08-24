<?php

declare(strict_types=1);

namespace Smithy\Http\Message;

use Psr\Http\Message\UriInterface;

/**
 * PSR-7 UriInterface implementation per RFC 3986.
 *
 * Immutable value object. All with*() methods return a new instance.
 */
final class Uri implements UriInterface
{
    private const DEFAULT_PORTS = [
        'http' => 80,
        'https' => 443
    ];

    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    /**
     * Parse a URI string into components.
     */
    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            return;
        }

        $parts = \parse_url($uri);
        if ($parts === false) {
            throw new \InvalidArgumentException("Unable to parse URI: {$uri}");
        }

        $this->scheme = isset($parts['scheme'])
            ? \strtolower($parts['scheme'])
            : '';
        $this->host = isset($parts['host'])
            ? \strtolower($parts['host'])
            : '';
        $this->port = isset($parts['port'])
            ? $this->filterPort($parts['port'])
            : null;
        $this->path = isset($parts['path'])
            ? $this->filterPath($parts['path'])
            : '';
        $this->query = isset($parts['query'])
            ? $this->filterQueryOrFragment($parts['query'])
            : '';
        $this->fragment = isset($parts['fragment'])
            ? $this->filterQueryOrFragment($parts['fragment'])
            : '';

        $user = $parts['user'] ?? '';
        $pass = $parts['pass'] ?? null;
        if ($user !== '') {
            $this->userInfo = $pass !== null ? "{$user}:{$pass}" : $user;
        }
    }

    /**
     * Get the URI scheme.
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * Get the URI authority.
     */
    public function getAuthority(): string
    {
        $authority = $this->host;

        if ($authority === '') {
            return '';
        }

        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }

        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    /**
     * Get the URI user information.
     */
    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    /**
     * Get the URI host.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Get the URI port.
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * Get the URI path.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get the URI query string.
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Get the URI fragment.
     */
    public function getFragment(): string
    {
        return $this->fragment;
    }

    /**
     * Return a URI with a replaced scheme.
     */
    public function withScheme(string $scheme): UriInterface
    {
        $scheme = \strtolower($scheme);
        if ($scheme === $this->scheme) {
            return $this;
        }

        $new = clone $this;
        $new->scheme = $scheme;
        $new->port = $new->filterPort($new->port);

        return $new;
    }

    /**
     * Return a URI with replaced user information.
     */
    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $info = $user;
        if ($password !== null && $password !== '') {
            $info .= ':' . $password;
        }

        if ($info === $this->userInfo) {
            return $this;
        }

        $new = clone $this;
        $new->userInfo = $info;

        return $new;
    }

    /**
     * Return a URI with a replaced host.
     */
    public function withHost(string $host): UriInterface
    {
        $host = \strtolower($host);
        if ($host === $this->host) {
            return $this;
        }

        $new = clone $this;
        $new->host = $host;

        return $new;
    }

    /**
     * Return a URI with a replaced port.
     */
    public function withPort(?int $port): UriInterface
    {
        $port = $this->filterPort($port);
        if ($port === $this->port) {
            return $this;
        }

        $new = clone $this;
        $new->port = $port;

        return $new;
    }

    /**
     * Return a URI with a replaced path.
     */
    public function withPath(string $path): UriInterface
    {
        $path = $this->filterPath($path);
        if ($path === $this->path) {
            return $this;
        }

        $new = clone $this;
        $new->path = $path;

        return $new;
    }

    /**
     * Return a URI with a replaced query string.
     */
    public function withQuery(string $query): UriInterface
    {
        $query = $this->filterQueryOrFragment($query);
        if ($query === $this->query) {
            return $this;
        }

        $new = clone $this;
        $new->query = $query;

        return $new;
    }

    /**
     * Return a URI with a replaced fragment.
     */
    public function withFragment(string $fragment): UriInterface
    {
        $fragment = $this->filterQueryOrFragment($fragment);
        if ($fragment === $this->fragment) {
            return $this;
        }

        $new = clone $this;
        $new->fragment = $fragment;

        return $new;
    }

    /**
     * Render the URI string.
     */
    public function __toString(): string
    {
        $uri = '';

        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }

        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= '//' . $authority;
        }

        $path = $this->path;
        if ($path !== '') {
            if ($authority !== '' && !\str_starts_with($path, '/')) {
                $path = '/' . $path;
            } elseif ($authority === '' && \str_starts_with($path, '//')) {
                $path = '/' . \ltrim($path, '/');
            }
        }

        $uri .= $path;

        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    /**
     * Return null for a default port on the current scheme.
     */
    private function filterPort(?int $port): ?int
    {
        if ($port === null) {
            return null;
        }

        if ($port < 0 || $port > 65535) {
            throw new \InvalidArgumentException(
                "Invalid port: {$port}. Must be between 0 and 65535."
            );
        }

        $defaultPort = self::DEFAULT_PORTS[$this->scheme] ?? null;
        if ($port === $defaultPort) {
            return null;
        }

        return $port;
    }

    /**
     * Percent-encode the path per RFC 3986 Section 3.3.
     */
    private function filterPath(string $path): string
    {
        return \preg_replace_callback(
            '/(?:[^a-zA-Z0-9_\-.~:@!$&\'()*+,;=%\/]+|%(?![A-Fa-f0-9]{2}))/',
            fn(array $match) => \rawurlencode($match[0]),
            $path
        );
    }

    /**
     * Percent-encode query or fragment per RFC 3986 Section 3.4/3.5.
     */
    private function filterQueryOrFragment(string $str): string
    {
        return \preg_replace_callback(
            '/(?:[^a-zA-Z0-9_\-.~!$&\'()*+,;=%:@\/?]+|%(?![A-Fa-f0-9]{2}))/',
            fn(array $match) => \rawurlencode($match[0]),
            $str
        );
    }
}
