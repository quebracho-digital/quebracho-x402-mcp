<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// The 'x402' Monolog channel (PaymentLog logs to it). Only with symfony/monolog-bundle, which is optional:
// without it this file returns nothing and PaymentLog logs through the PSR-3 logger with the 'x402 ' prefix.
if (!class_exists(Symfony\Bundle\MonologBundle\MonologBundle::class)) {
    return;
}

return static function (ContainerConfigurator $container): void {
    $container->extension('monolog', ['channels' => ['x402']]);
};
