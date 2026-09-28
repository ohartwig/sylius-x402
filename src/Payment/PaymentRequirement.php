<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\Payment;

/**
 * What a caller must pay to reach one resource.
 */
final readonly class PaymentRequirement
{
    /**
     * @param string       $amount  ASCII digits in the asset's smallest unit
     * @param list<string> $methods HTTP methods this rule applies to; empty = all
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
        public array $methods = [],
    ) {
    }

    /**
     * Whether this rule speaks for the given method.
     *
     * An empty list means every method, which is the right default for a feed:
     * gating GET but leaving HEAD free would let a caller confirm a resource
     * exists, and its size, without paying. A rule that names methods is the
     * exception — gating POST on a transport endpoint whose GET is a harmless
     * capability probe.
     */
    public function coversMethod(string $method): bool
    {
        return [] === $this->methods || \in_array(strtoupper($method), $this->methods, true);
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
