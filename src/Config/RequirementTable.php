<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\Config;

use Koh\SyliusX402\Payment\PaymentRequirement;

/**
 * Maps request paths to their price.
 *
 * Path patterns rather than route attributes: the gated routes come from other
 * packages — a product feed here, an MCP transport there — and this bundle
 * cannot annotate classes it does not own.
 */
final class RequirementTable
{
    /** @param list<PaymentRequirement> $requirements */
    public function __construct(private readonly array $requirements = [])
    {
    }

    public function match(string $path, string $method = 'GET'): ?PaymentRequirement
    {
        $method = strtoupper($method);

        foreach ($this->requirements as $requirement) {
            if (!$requirement->coversMethod($method)) {
                continue;
            }
            if (1 === preg_match('{'.$requirement->pattern.'}', $path)) {
                return $requirement;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->requirements;
    }

    /** @return list<PaymentRequirement> */
    public function all(): array
    {
        return $this->requirements;
    }
}
