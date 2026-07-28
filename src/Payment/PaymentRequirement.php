<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\Payment;

/**
 * What a caller must pay to reach one resource.
 */
final readonly class PaymentRequirement
{
    /**
     * @param string $amount ASCII digits in the asset's smallest unit
     */
    public function __construct(
        public string $pattern,
        public string $amount,
        public string $network,
        public string $asset,
        public string $payTo,
        public ?string $description = null,
        public string $scheme = 'exact',
        public int $maxTimeoutSeconds = 60,
    ) {
    }

    /**
     * The `accepts` entry of an HTTP 402 body.
     *
     * @return array<string, mixed>
     */
    public function toAccepts(string $resource): array
    {
        return [
            'scheme' => $this->scheme,
            'network' => $this->network,
            'maxAmountRequired' => $this->amount,
            'resource' => $resource,
            'description' => $this->description ?? '',
            'mimeType' => 'application/json',
            'payTo' => $this->payTo,
            'maxTimeoutSeconds' => $this->maxTimeoutSeconds,
            'asset' => $this->asset,
        ];
    }
}
