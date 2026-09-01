<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Curl;

use Psr\Http\Message\RequestInterface;
use Smithy\Http\Client\Exception\NetworkException;

/**
 * Describe a failed cURL transfer.
 *
 * @internal
 */
final class CurlTransferException extends NetworkException
{
    public function __construct(
        string $message,
        RequestInterface $request,
        public readonly int $curlCode,
        public readonly bool $connectionFailure = false,
        ?\Throwable $previous = null,
        bool $requestBodyStarted = false,
        bool $requestBodyFullyConsumed = false,
        ?bool $requestBodySeekable = null
    )
    {
        parent::__construct(
            $message,
            $request,
            $previous,
            $requestBodyStarted,
            $requestBodyFullyConsumed,
            $requestBodySeekable
        );
    }
}
