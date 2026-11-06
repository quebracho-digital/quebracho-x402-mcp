<?php

declare(strict_types=1);

namespace App\Tests\X402;

use App\X402\PaymentLogFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Log\Logger;

/**
 * The stderr logger of PaymentLog without monolog: the context must reach the line, not only placeholders.
 */
final class PaymentLogFormatterTest extends TestCase
{
    public function testTheContextIsWrittenNotDropped(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'x402log');
        try {
            $logger = new Logger('info', $file, new PaymentLogFormatter());
            $logger->log('error', 'x402 outcome', ['status' => 'failed_after_settle', 'transaction' => '0xabc', 'network' => 'eip155:8453']);
            $line = trim((string) file_get_contents($file));
        } finally {
            @unlink($file);
        }

        self::assertStringContainsString('[error] x402 outcome', $line);
        self::assertStringContainsString('"transaction":"0xabc"', $line);
        self::assertStringContainsString('"network":"eip155:8453"', $line);
    }

    public function testAMessageWithoutContextHasNoTrailingJson(): void
    {
        self::assertStringEndsWith('[info] x402 intent', (new PaymentLogFormatter())('info', 'x402 intent', [], false));
    }
}
