<?php

declare(strict_types=1);

namespace Smithy\Http\Client;

use Smithy\Client\Transport\{
    TransportInterface,
    TransportOptionsInterface,
    TransportRequestInterface,
    TransportRequestOverrideInterface
};
use Smithy\Http\Client\Cancellation\CancellationInterface;
use Smithy\Http\Message\{
    HttpMessageExchange,
    HttpTransportRequest
};

/**
 * Apply HTTP headers and request options to one client request.
 */
final readonly class HttpRequestOverride implements
    TransportRequestOverrideInterface
{
    public const KEY = '@http';

    /**
     * @param array<string, mixed> $optionOverrides Partial HTTP options.
     * @param array<string, string|list<string>> $headers Request headers.
     */
    private function __construct(
        private array $optionOverrides,
        public array $headers,
        public ?HttpRequestOptions $options
    ) {}

    /**
     * Create an HTTP override from its public request-override value.
     */
    public static function fromValue(mixed $value): self
    {
        $options = null;
        $optionOverrides = [];
        $headers = [];
        if ($value instanceof HttpRequestOptions) {
            $options = $value;
        } elseif (\is_array($value)) {
            $optionOverrides = $value;
            $headers = self::extractHeaders($optionOverrides);
            self::validateOptionOverrides($optionOverrides);
        } else {
            throw new \InvalidArgumentException(
                self::KEY
                    . ' must be an array or '
                    . HttpRequestOptions::class
                    . '.'
            );
        }

        return new self($optionOverrides, $headers, $options);
    }

    /** @inheritDoc */
    public function resolveOptions(
        TransportInterface $transport
    ): ?TransportOptionsInterface
    {
        if ($this->options === null
            && $this->optionOverrides === []
            && $this->headers === []
        ) {
            return null;
        }
        if (!HttpMessageExchange::instance()->isCompatibleWith(
            $transport->getMessageExchange()
        )) {
            throw new \InvalidArgumentException(
                'HTTP request overrides require an HTTP transport.'
            );
        }

        if ($this->options === null && $this->optionOverrides === []) {
            return null;
        }

        $defaults = $transport->getDefaultOptions();
        if (!$defaults instanceof HttpRequestOptions) {
            throw new \InvalidArgumentException(
                'HTTP request option overrides require a transport that '
                    . 'supports ' . HttpRequestOptions::class . '.'
            );
        }

        return $this->resolveHttpOptions($defaults);
    }

    /** @inheritDoc */
    public function applyToRequest(
        TransportRequestInterface $request
    ): TransportRequestInterface
    {
        if ($this->headers === []) {
            return $request;
        }

        $transportRequest = HttpTransportRequest::expect($request);
        $httpRequest = $transportRequest->request;
        foreach ($this->headers as $name => $values) {
            $httpRequest = $httpRequest->withHeader($name, $values);
        }

        return $transportRequest->withRequest($httpRequest);
    }

    /**
     * Apply partial HTTP overrides to client defaults.
     *
     * @throws \InvalidArgumentException If an HTTP option is invalid.
     */
    public function resolveHttpOptions(
        HttpRequestOptions $defaults
    ): ?HttpRequestOptions
    {
        if ($this->options !== null) {
            return $this->options;
        }

        if ($this->optionOverrides === []) {
            return null;
        }

        $options = $this->optionOverrides;

        return new HttpRequestOptions(
            timeout: self::floatOption(
                $options,
                'timeout',
                $defaults->timeout
            ),
            proxy: self::nullableStringOption(
                $options,
                'proxy',
                $defaults->proxy
            ),
            decodeContent: self::boolOption(
                $options,
                'decode_content',
                $defaults->decodeContent
            ),
            trailers: self::objectOption(
                $options,
                'trailers',
                RequestTrailers::class,
                $defaults->trailers
            ),
            cancellation: self::objectOption(
                $options,
                'cancellation',
                CancellationInterface::class,
                $defaults->cancellation
            ),
            responseBufferSize: self::intOption(
                $options,
                'response_buffer_size',
                $defaults->responseBufferSize
            ),
            maxBufferedResponseBytes: self::intOption(
                $options,
                'max_buffered_response_bytes',
                $defaults->maxBufferedResponseBytes
            ),
            readTimeout: self::floatOption(
                $options,
                'read_timeout',
                $defaults->readTimeout
            ),
            maxResponseBodyBytes: self::intOption(
                $options,
                'max_response_body_bytes',
                $defaults->maxResponseBodyBytes
            ),
            uploadBufferSize: self::intOption(
                $options,
                'upload_buffer_size',
                $defaults->uploadBufferSize
            ),
            bufferedDownloadBufferSize: self::intOption(
                $options,
                'buffered_download_buffer_size',
                $defaults->bufferedDownloadBufferSize
            )
        );
    }

    /**
     * Extract request headers from HTTP overrides.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, string|list<string>>
     */
    private static function extractHeaders(array &$options): array
    {
        $headers = $options['headers'] ?? [];
        unset($options['headers']);

        if (!\is_array($headers)) {
            throw new \InvalidArgumentException(
                self::KEY . '.headers must be an associative array.'
            );
        }

        foreach ($headers as $name => $value) {
            if (!\is_string($name) || $name === '') {
                throw new \InvalidArgumentException(
                    self::KEY
                        . '.headers names must be non-empty strings.'
                );
            }

            $values = \is_array($value) ? $value : [$value];
            if (!\array_is_list($values)) {
                throw new \InvalidArgumentException(
                    self::KEY
                        . ".headers['{$name}'] must be a list of strings."
                );
            }

            if ($values === []) {
                throw new \InvalidArgumentException(
                    self::KEY
                        . ".headers['{$name}'] must not be empty."
                );
            }

            foreach ($values as $headerValue) {
                if (!\is_string($headerValue)) {
                    throw new \InvalidArgumentException(
                        self::KEY
                            . ".headers['{$name}'] values must be strings."
                    );
                }
            }
        }

        return $headers;
    }

    /**
     * Validate partial HTTP option names.
     *
     * @param array<string, mixed> $options
     */
    private static function validateOptionOverrides(array $options): void
    {
        $known = [
            'timeout' => true,
            'proxy' => true,
            'decode_content' => true,
            'trailers' => true,
            'cancellation' => true,
            'response_buffer_size' => true,
            'max_buffered_response_bytes' => true,
            'read_timeout' => true,
            'max_response_body_bytes' => true,
            'upload_buffer_size' => true,
            'buffered_download_buffer_size' => true
        ];

        foreach ($options as $name => $_) {
            if (!\is_string($name) || !isset($known[$name])) {
                throw new \InvalidArgumentException(
                    'Unknown ' . self::KEY . " option '{$name}'."
                );
            }
        }
    }

    /** @param array<string, mixed> $options */
    private static function floatOption(
        array $options,
        string $name,
        float $default
    ): float
    {
        if (!isset($options[$name])) {
            return $default;
        }

        $value = $options[$name];
        if (!\is_int($value) && !\is_float($value)) {
            throw new \InvalidArgumentException(
                self::KEY . ".{$name} must be numeric."
            );
        }

        return (float) $value;
    }

    /** @param array<string, mixed> $options */
    private static function intOption(
        array $options,
        string $name,
        int $default
    ): int
    {
        if (!isset($options[$name])) {
            return $default;
        }

        if (!\is_int($options[$name])) {
            throw new \InvalidArgumentException(
                self::KEY . ".{$name} must be an integer."
            );
        }

        return $options[$name];
    }

    /** @param array<string, mixed> $options */
    private static function boolOption(
        array $options,
        string $name,
        bool $default
    ): bool
    {
        if (!isset($options[$name])) {
            return $default;
        }

        if (!\is_bool($options[$name])) {
            throw new \InvalidArgumentException(
                self::KEY . ".{$name} must be a boolean."
            );
        }

        return $options[$name];
    }

    /** @param array<string, mixed> $options */
    private static function nullableStringOption(
        array $options,
        string $name,
        ?string $default
    ): ?string
    {
        if (!isset($options[$name])) {
            return $default;
        }

        $value = $options[$name];
        if ($value !== null && !\is_string($value)) {
            throw new \InvalidArgumentException(
                self::KEY . ".{$name} must be a string or null."
            );
        }

        return $value;
    }

    /**
     * Resolve an object-valued HTTP option.
     *
     * @template T of object
     *
     * @param array<string, mixed> $options
     * @param class-string<T> $class
     * @param T|null $default
     *
     * @return T|null
     */
    private static function objectOption(
        array $options,
        string $name,
        string $class,
        ?object $default
    ): ?object
    {
        if (!isset($options[$name])) {
            return $default;
        }

        $value = $options[$name];
        if ($value !== null && !$value instanceof $class) {
            throw new \InvalidArgumentException(
                self::KEY . ".{$name} must be an instance of {$class}."
            );
        }

        return $value;
    }
}
