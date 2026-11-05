<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client\Configuration;

/**
 * Select an HTTP protocol version.
 */
enum HTTPVersionPolicy: string
{
    /** Enforce HTTP/1.1 only. */
    case EnforceHTTP_1_1 = 'http1.1';

    /** Enforce HTTP/2 only. */
    case EnforceHTTP_2 = 'h2';

    /**
     * Use ALPN to negotiate the protocol.
     *
     * - If no ALPN extension is returned, HTTP/1.1 is used.
     * - If HTTP/1.1 is in the ALPN extension, HTTP/1.1 is used.
     * - If h2 is in the ALPN string, HTTP/2 is used.
     */
    case Automatic = 'automatic';
}
