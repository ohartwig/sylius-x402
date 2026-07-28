<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\Config;

use Koh\SyliusX402\Payment\PaymentRequirement;

final class RequirementTableFactory
{
    /**
     * @param list<array{pattern: string, amount: string, description?: string|null}> $resources
     */
    public static function create(array $resources, string $network, string $asset, string $payTo): RequirementTable
    {
        $requirements = [];
        foreach ($resources as $resource) {
            $requirements[] = new PaymentRequirement(
                pattern: $resource['pattern'],
                amount: (string) $resource['amount'],
                network: $network,
                asset: $asset,
                payTo: $payTo,
                description: $resource['description'] ?? null,
            );
        }

        return new RequirementTable($requirements);
    }
}
