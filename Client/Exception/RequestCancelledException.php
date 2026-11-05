<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client\Exception;

/**
 * Indicate that an HTTP request was cancelled.
 */
final class RequestCancelledException extends HttpException {}
