<?php

declare(strict_types=1);

namespace Smithy\Http\Connection;

/**
 * The HTTP protocol version actually negotiated on a connection.
 *
 * Distinct from HTTPVersionPolicy (which expresses a preference).
 * This enum records what was actually negotiated via ALPN or
 * prior knowledge.
 */
enum HttpVersion: string
{
    case HTTP_1_1 = '1.1';
    case HTTP_2 = '2';
}
