<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\X402ReconcileCommand;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\AbstractLogger;
use Quebracho\X402\FileLedger;
use Quebracho\X402\Reconciler;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;

final class X402ReconcileCommandTest extends KernelTestCase
{
    public function testTheRegisteredCommandRunsAndExitsZero(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:x402:reconcile'));

        self::assertSame(0, $tester->execute(['--limit' => '5']));
    }

    public function testItPrintsOneRowPerOpenKeyAndExitsZero(): void
    {
        $dir = sys_get_temp_dir().'/x402-reconcile-cmd-'.bin2hex(random_bytes(6));
        $ledger = new FileLedger($dir);
        try {
            $ledger->put('k', ['status' => 'settling'], 600);
            // A network with no rpc declared: rpc_error, and no node is asked.
            $ledger->open('k', ['network' => 'eip155:999999', 'asset' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e', 'from' => '0x2c7536e3605d9c16a7a3d7b1898e529396a65c23', 'nonce' => '0x'.str_repeat('ab', 32), 'status' => 'settling'], 600);
            $f = new Psr17Factory();
            $logger = new class extends AbstractLogger {
                /** @var list<array{string, string, array<string, mixed>}> */
                public array $lines = [];

                public function log($level, \Stringable|string $message, array $context = []): void
                {
                    $this->lines[] = [(string) $level, (string) $message, $context];
                }
            };
            $command = new X402ReconcileCommand(new Reconciler($ledger, new Psr18Client(new MockHttpClient()), $f, $f), $logger);

            $code = $command(new SymfonyStyle(new ArrayInput([]), $out = new BufferedOutput()));
            $display = $out->fetch();
            self::assertSame(0, $code);
            self::assertMatchesRegularExpression('/k\s+settling\s+rpc_error\s+no rpc declared for eip155:999999/', $display);
            // A row a person must look at also goes to the log.
            self::assertSame([['warning', 'rpc_error']], array_map(static fn (array $l) => [$l[0], $l[2]['result']], $logger->lines));

            $ledger->close('k');
            $command(new SymfonyStyle(new ArrayInput([]), $out = new BufferedOutput()));
            self::assertStringContainsString('nothing to reconcile', $out->fetch());
        } finally {
            array_map('unlink', array_filter(glob($dir.'/{,.}*', \GLOB_BRACE) ?: [], 'is_file'));
            @rmdir($dir);
        }
    }
}
