<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\Agent;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\PayableOperation;
use Koh\SyliusAgentContracts\Provider\PayableOperationProviderInterface;
use Koh\SyliusX402\Config\RequirementTable;

/**
 * Publishes the price table so MPP can declare it in the OpenAPI document.
 *
 * One source for both: a route can never advertise a price it does not charge,
 * because the declaration and the enforcement read the same table.
 */
final class X402PayableOperationProvider implements PayableOperationProviderInterface
{
    public function __construct(
        private readonly RequirementTable $requirements,
        private readonly bool $enabled = false,
    ) {
    }

    public function getPayableOperations(AgentContext $context): iterable
    {
        if (!$this->enabled) {
            return;
        }

        foreach ($this->requirements->all() as $requirement) {
            yield new PayableOperation(
                // The pattern is a regex; strip the anchor so the declared path
                // is something a client can actually request.
                path: '/'.ltrim(str_replace(['^', '$'], '', $requirement->pattern), '/'),
                intent: 'charge',
                payMethod: 'x402',
                amount: $requirement->amount,
                currency: $requirement->asset,
                description: $requirement->description,
            );
        }
    }
}
