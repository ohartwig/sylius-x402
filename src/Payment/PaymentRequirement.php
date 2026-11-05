<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\Payment;

/**
 * What a caller must pay to reach one resource.
 *
 * Shaped after x402 protocol version 2 (coinbase/x402,
 * specs/x402-specification-v2.md, section 5.1): one `PaymentRequirements`
 * entry of the `accepts` list, plus what the 402 needs to describe the
 * resource itself (`ResourceInfo`).
 */
final readonly class PaymentRequirement
{
    /**
     * @param string               $amount  ASCII digits in the asset's smallest unit
     * @param string               $network CAIP-2 chain id, e.g. 'eip155:84532'
     * @param list<string>         $methods HTTP methods this rule applies to; empty = all
     * @param array<string, mixed> $extra   scheme-specific `extra`; for `exact` on
     *                                      EVM with EIP-3009 the EIP-712 domain
     *                                      `name` and `version` of the token
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
        public array $extra = [],
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
     * The `PaymentRequirements` object of an x402 v2 `accepts` list.
     *
     * Version 2 moved the resource out of this object (into `ResourceInfo`, see
     * {@see self::toResourceInfo()}) and renamed `maxAmountRequired` to
     * `amount`. `extra` is left out when empty: it is optional, and an empty
     * JSON array where an object belongs would be wrong.
     *
     * @return array<string, mixed>
     */
    public function toAccepts(): array
    {
        $accepts = [
            'scheme' => $this->scheme,
            'network' => $this->network,
            'amount' => $this->amount,
            'asset' => $this->asset,
            'payTo' => $this->payTo,
            'maxTimeoutSeconds' => $this->maxTimeoutSeconds,
        ];

        if ([] !== $this->extra) {
            $accepts['extra'] = $this->extra;
        }

        return $accepts;
    }

    /**
     * The `ResourceInfo` object of an x402 v2 `PaymentRequired`.
     *
     * @param string $url the gated URL, echoed back so the payer knows what they
     *                    are paying for
     *
     * @return array<string, string>
     */
    public function toResourceInfo(string $url): array
    {
        $resource = ['url' => $url];
        if (null !== $this->description && '' !== $this->description) {
            $resource['description'] = $this->description;
        }
        $resource['mimeType'] = 'application/json';

        return $resource;
    }
}
