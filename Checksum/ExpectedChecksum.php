<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Checksum;

use Smithy\Core\Checksum\ChecksumAlgorithm;

/**
 * A response checksum to validate: the algorithm and the base64 digest the
 * response reported. The digest is decoded only when it is compared.
 */
final class ExpectedChecksum
{
    public function __construct(
        public readonly ChecksumAlgorithm $algorithm,
        public readonly string $base64Digest,
    ) {}

    /**
     * Return the expected digest as raw bytes.
     */
    public function digest(): string
    {
        $digest = \base64_decode($this->base64Digest, true);

        return $digest === false ? '' : $digest;
    }

    /**
     * Report whether raw digest bytes match the expected digest.
     */
    public function matches(string $digest): bool
    {
        return \hash_equals($this->digest(), $digest);
    }
}
