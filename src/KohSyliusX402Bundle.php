<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * x402 payment middleware for API routes.
 */
final class KohSyliusX402Bundle extends AbstractBundle
{
    protected string $extensionAlias = 'koh_x402';

    /** config/ sits at the package root, not under src/. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')
                    ->defaultFalse()
                    ->info('Off by default. Installing this bundle must not start charging for anything.')
                ->end()
                ->scalarNode('network')
                    ->defaultValue('eip155:84532')
                    ->info('CAIP-2 chain id. Default is Base Sepolia — a testnet.')
                ->end()
                ->scalarNode('asset')
                    ->defaultValue('')
                    ->info('Contract address of the settlement asset.')
                ->end()
                ->scalarNode('pay_to')
                    ->defaultValue('')
                    ->info('Receiving address.')
                ->end()
                ->arrayNode('resources')
                    ->info('Paths to gate. Never the storefront or the checkout.')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('pattern')->isRequired()->info('Regex against the path.')->end()
                            ->scalarNode('amount')->isRequired()->info('Smallest unit, as digits.')->end()
                            ->scalarNode('description')->defaultNull()->end()
                            ->booleanNode('enabled')->defaultTrue()->end()
                            ->arrayNode('methods')
                                ->scalarPrototype()->end()
                                ->defaultValue([])
                                ->info('HTTP methods this rule gates. Empty means all — gating GET but leaving HEAD free would give away existence and size for nothing.')
                            ->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
            ->end();
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $resources = [];
        foreach ($config['resources'] ?? [] as $resource) {
            if (\is_array($resource) && false !== ($resource['enabled'] ?? true)) {
                $resources[] = $resource;
            }
        }

        // Refuse to gate anything without a payee. Advertising a price with no
        // address to pay would collect nothing while denying access.
        $payTo = (string) ($config['pay_to'] ?? '');
        $enabled = (bool) ($config['enabled'] ?? false);
        if ($enabled && '' === $payTo) {
            throw new \InvalidArgumentException(
                'koh_x402.pay_to must be set when koh_x402.enabled is true — otherwise the shop would refuse access and collect nothing.',
            );
        }

        $builder->setParameter('koh_x402.enabled', $enabled);
        $builder->setParameter('koh_x402.network', (string) ($config['network'] ?? 'eip155:84532'));
        $builder->setParameter('koh_x402.asset', (string) ($config['asset'] ?? ''));
        $builder->setParameter('koh_x402.pay_to', $payTo);
        $builder->setParameter('koh_x402.resources', $resources);

        $container->import('../config/services.php');
    }
}
