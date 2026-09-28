<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\Config;

use Koh\SyliusX402\Payment\PaymentRequirement;

final class RequirementTableFactory
{
    /**
     * @param list<array{pattern: string, amount: string, description?: string|null, methods?: list<string>}> $resources
     */
    public static function create(array $resources, string $network, string $asset, string $payTo): RequirementTable
    {
        $requirements = [];
        foreach ($resources as $resource) {
            $methods = array_map(
                static fn (string $m): string => strtoupper($m),
                $resource['methods'] ?? [],
            );

            $requirements[] = new PaymentRequirement(
                pattern: $resource['pattern'],
                amount: $resource['amount'],
                network: $network,
                asset: $asset,
                payTo: $payTo,
                description: $resource['description'] ?? null,
                methods: $methods,
            );
        }

        return new RequirementTable($requirements);
    }
}
