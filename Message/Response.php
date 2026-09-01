<?php

declare(strict_types=1);

namespace Smithy\Http\Message;

use Psr\Http\Message\StreamInterface;

/**
 * Implement an immutable Smithy HTTP response.
 *
 * All with*() methods return a new instance.
 *
 * This is the HTTP response type returned by the transport layer. The
 * deserializer consumes it to produce typed operation output shapes.
 */
final class Response implements ResponseInterface
{
    use MessageTrait;

    /** @var array<int, string> RFC 7231 default reason phrases. */
    private const REASON_PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required'
    ];

    private int $statusCode;
    private string $reasonPhrase;
    private readonly ResponseTrailers $trailers;

    /**
     * @param int $status HTTP status code
     * @param array<string, string|string[]> $headers Response headers
     * @param string|StreamInterface|null $body Response body
     * @param string $protocolVersion HTTP protocol version
     * @param string $reason Reason phrase
     * @param ResponseTrailers|null $trailers Shared response trailer state
     * @param bool $normalizedHeaders Whether headers use the trusted internal
     *     normalized representation
     *
     * @throws \InvalidArgumentException If message metadata is invalid.
     */
    public function __construct(
        int $status = 200,
        array $headers = [],
        string|StreamInterface|null $body = null,
        string $protocolVersion = '1.1',
        string $reason = '',
        ?ResponseTrailers $trailers = null,
        bool $normalizedHeaders = false
    )
    {
        HttpMessageValidator::validateStatusCode($status);
        HttpMessageValidator::validateReasonPhrase($reason);
        HttpMessageValidator::validateProtocolVersion($protocolVersion);
        $this->statusCode = $status;
        $this->reasonPhrase = $reason !== ''
            ? $reason
            : (self::REASON_PHRASES[$status] ?? '');
        $this->protocolVersion = $protocolVersion;
        $this->trailers = $trailers ?? ResponseTrailers::completed();

        if ($headers !== []) {
            $normalizedHeaders
                ? $this->headers = $headers
                : $this->setHeaders($headers);
        }

        if ($body instanceof ResponseStreamInterface) {
            $this->body = $body;
        } elseif ($body instanceof StreamInterface) {
            $this->body = $body;
        } elseif (\is_string($body) && $body !== '') {
            $this->body = Stream::create($body);
        } else {
            $this->body = Stream::empty();
        }
    }

    /**
     * Create a response from trusted normalized headers.
     *
     * @param array<string, array{0: string, 1: list<string>}> $headers
     */
    public static function fromNormalized(
        int $status = 200,
        array $headers = [],
        string|StreamInterface|null $body = null,
        string $protocolVersion = '1.1',
        string $reason = '',
        ?ResponseTrailers $trailers = null
    ): self
    {
        return new self(
            $status,
            $headers,
            $body,
            $protocolVersion,
            $reason,
            $trailers,
            normalizedHeaders: true
        );
    }

    /**
     * Get the HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Return a response with a replaced status code and reason phrase.
     */
    public function withStatus(
        int $code,
        string $reasonPhrase = ''
    ): static
    {
        HttpMessageValidator::validateStatusCode($code);
        HttpMessageValidator::validateReasonPhrase($reasonPhrase);

        $new = clone $this;
        $new->statusCode = $code;
        $new->reasonPhrase = $reasonPhrase !== ''
            ? $reasonPhrase
            : (self::REASON_PHRASES[$code] ?? '');

        return $new;
    }

    /**
     * Get the response reason phrase.
     */
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /**
     * Discard unread body bytes while preserving transport reuse.
     *
     * @return void
     */
    public function discardBody(): void
    {
        if ($this->body instanceof ResponseStreamInterface) {
            $this->body->discard();

            return;
        }

        $this->body->close();
    }

    /**
     * Get the shared response trailer state.
     */
    public function getTrailers(): ResponseTrailers
    {
        return $this->trailers;
    }
}
