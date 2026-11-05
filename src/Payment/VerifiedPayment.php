<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\Payment;

final readonly class VerifiedPayment
{
    /** @param array<string, mixed> $payload the decoded `PaymentPayload` of the PAYMENT-SIGNATURE header */
    public function __construct(
        public PaymentRequirement $requirement,
        public array $payload = [],
    ) {
    }
}
