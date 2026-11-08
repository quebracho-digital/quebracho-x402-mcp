<?php

declare(strict_types=1);

namespace App\X402;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Quebracho\X402\PaymentLog;

/**
 * The library's PaymentLog, on the 'x402' Monolog channel when symfony/monolog-bundle is installed (the
 * $channels locator holds it only then), else on the PSR-3 logger with each line prefixed 'x402 '. Decided when
 * the service is first built, not when the container compiles, so the container compiles with or without the bundle.
 */
final class PaymentLogFactory
{
    public static function create(LoggerInterface $logger, ContainerInterface $channels): PaymentLog
    {
        $channel = $channels->has('x402') ? $channels->get('x402') : null;

        return $channel instanceof LoggerInterface ? new PaymentLog($channel, '') : new PaymentLog($logger);
    }
}
