<?php

declare(strict_types=1);

namespace App\Tests\X402;

use Quebracho\X402\FileLedger;
use Quebracho\X402\X402McpMiddleware;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LedgerWiringTest extends KernelTestCase
{
    public function testTheMcpMiddlewareUsesThisHostsFilesWhenNoRedisUrlIsSet(): void
    {
        if (($_ENV['X402_REDIS_URL'] ?? $_SERVER['X402_REDIS_URL'] ?? '') !== '') {
            self::markTestSkipped('X402_REDIS_URL is set in this environment; the file wiring is what this test pins.');
        }
        $middleware = self::getContainer()->get(X402McpMiddleware::class);
        $ledger = (new \ReflectionProperty(X402McpMiddleware::class, 'ledger'))->getValue($middleware);

        self::assertInstanceOf(FileLedger::class, $ledger);
    }
}
