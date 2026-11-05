<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client\Connection;

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
