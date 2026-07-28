<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusX402\Agent\X402PayableOperationProvider;
use Koh\SyliusX402\Config\RequirementTable;
use Koh\SyliusX402\Config\RequirementTableFactory;
use Koh\SyliusX402\EventListener\X402RequestListener;
use Koh\SyliusX402\EventListener\X402ResponseListener;
use Koh\SyliusX402\Payment\NullPaymentVerifier;
use Koh\SyliusX402\Payment\PaymentVerifierInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(RequirementTable::class)
        ->factory([RequirementTableFactory::class, 'create'])
        ->args([
            param('koh_x402.resources'),
            param('koh_x402.network'),
            param('koh_x402.asset'),
            param('koh_x402.pay_to'),
        ])
        ->public();

    // Alias this to a settling implementation once one is configured.
    $services->set(NullPaymentVerifier::class);
    $services->alias(PaymentVerifierInterface::class, NullPaymentVerifier::class)->public();

    // Priority 5: below the firewall (8), so the security token exists when the
    // bypass voters run.
    $services->set(X402RequestListener::class)
        ->args([
            service(RequirementTable::class),
            service(PaymentVerifierInterface::class),
            tagged_iterator('koh_agent.payment_bypass'),
            param('koh_x402.enabled'),
        ])
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => '__invoke', 'priority' => 5]);

    $services->set(X402ResponseListener::class)
        ->args([service(PaymentVerifierInterface::class), service('logger')->nullOnInvalid()])
        ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => '__invoke']);

    $services->set(X402PayableOperationProvider::class)
        ->args([service(RequirementTable::class), param('koh_x402.enabled')])
        ->tag('koh_agent.payable_operation');
};
