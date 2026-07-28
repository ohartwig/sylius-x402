<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\Payment;

final readonly class VerifiedPayment
{
    /** @param array<string, mixed> $payload the decoded X-PAYMENT header */
    public function __construct(
        public PaymentRequirement $requirement,
        public array $payload = [],
    ) {
    }
}
