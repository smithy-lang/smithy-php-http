<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Exception;

use Psr\Http\Message\RequestInterface;

/**
 * Remove potentially sensitive headers from requests retained by exceptions.
 *
 * @internal
 */
final class ExceptionRequestSanitizer
{
    private const SENSITIVE_HEADER_FRAGMENTS = [
        'authorization',
        'password',
        'token',
    ];

    /**
     * Return a request that is safe to expose through an exception.
     */
    public static function sanitize(
        RequestInterface $request
    ): RequestInterface
    {
        foreach (\array_keys($request->getHeaders()) as $header) {
            $normalizedHeader = \strtolower($header);

            foreach (self::SENSITIVE_HEADER_FRAGMENTS as $fragment) {
                if (\str_contains($normalizedHeader, $fragment)) {
                    $request = $request->withoutHeader($header);
                    break;
                }
            }
        }

        return $request;
    }
}
