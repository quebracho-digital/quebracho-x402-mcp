<?php

declare(strict_types=1);

namespace App\X402;

/**
 * The line format of the payment log when monolog is not installed (services.yaml, app.x402_stderr_logger).
 *
 * HttpKernel's Logger only fills {placeholders} from the context and drops the rest, so an x402 line
 * would reach stderr without its key, network, amount or transaction. This keeps the context as JSON.
 */
final class PaymentLogFormatter
{
    public function __invoke(string $level, string $message, array $context, bool $prefixDate = true): string
    {
        $json = [] === $context ? '' : ' '.json_encode($context, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        $line = \sprintf('[%s] %s%s', $level, $message, $json);

        return $prefixDate ? date(\DateTimeInterface::RFC3339).' '.$line : $line;
    }
}
