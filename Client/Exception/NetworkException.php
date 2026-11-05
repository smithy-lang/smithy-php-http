<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client\Exception;

use Psr\Http\Client\NetworkExceptionInterface;

/**
 * Thrown on network failures while sending a request.
 */
class NetworkException extends HttpException implements
    NetworkExceptionInterface {}
