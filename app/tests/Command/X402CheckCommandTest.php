<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\X402CheckCommand;
use Quebracho\X402\EvmExact;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class X402CheckCommandTest extends KernelTestCase
{
    /** @var array<string, array{mixed, mixed}> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => [$env, $server]) {
            $_ENV[$name] = $env;
            $_SERVER[$name] = $server;
        }
        parent::tearDown();
    }

    /** @param array<string, string> $env */
    private function check(array $env = []): CommandTester
    {
        foreach ($env as $name => $value) {
            $this->saved[$name] ??= [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:x402:check'));
        $tester->execute([]);

        return $tester;
    }

    public function testTheCommittedEnvPasses(): void
    {
        $tester = $this->check();
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('x402 env checked', $tester->getDisplay());
    }

    public function testNoPaidToolsIsAWarningNotAFailure(): void
    {
        // Built by hand: an empty env var in a kernel test falls back to the .env loaders.
        $run = static function (string $paid): array {
            $limiter = static fn () => new RateLimiterFactory(['id' => 'x', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'], new InMemoryStorage());
            $limits = ['MCP_LIMIT_IP' => '1', 'MCP_LIMIT_GLOBAL' => '1', 'TERMS_LIMIT_IP' => '1', 'TERMS_LIMIT_DAY' => '1'];
            $command = new X402CheckCommand(static fn () => null, static fn () => null, $limiter, $limiter, $limiter, $limiter, $limits, '{}', '170', 'eip155:84532', [new EvmExact()], $paid);
            $code = $command(new SymfonyStyle(new ArrayInput([]), $out = new BufferedOutput()));

            return [$code, $out->fetch()];
        };

        [$code, $display] = $run('');
        self::assertSame(0, $code);
        self::assertStringContainsString('X402_PAID_TOOLS is empty', $display);
        self::assertStringNotContainsString('X402_PAID_TOOLS is empty', $run('wisdom')[1]);
    }

    public function testAnOfferExactlyAsLongAsOurBuyerSignsPasses(): void
    {
        self::assertSame(0, $this->check(['X402_BUYER_MAX_TIMEOUT' => '120'])->getStatusCode());
    }

    public function testAnUnreachableRedisFailsTheLedgerCheck(): void
    {
        $tester = $this->check(['X402_REDIS_URL' => 'redis://127.0.0.1:1']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('payment ledger', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    public function testARedisThatAnswersPingButRefusesClaimsFailsTheLedgerCheck(): void
    {
        // db 99 is out of range: PING answers, SELECT and every claim are refused.
        $tester = $this->check(['X402_REDIS_URL' => 'redis://redis:6379/99']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('payment ledger', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function bad(): iterable
    {
        yield 'MCP_LIMIT_IP zero' => [['MCP_LIMIT_IP' => '0'], 'MCP_LIMIT_IP: must be a positive integer, got "0"'];
        yield 'MCP_LIMIT_IP negative' => [['MCP_LIMIT_IP' => '-5'], 'MCP_LIMIT_IP: must be a positive integer'];
        yield 'MCP_LIMIT_GLOBAL not a number' => [['MCP_LIMIT_GLOBAL' => 'lots'], 'MCP_LIMIT_GLOBAL: must be a positive integer'];
        yield 'TERMS_LIMIT_IP zero' => [['TERMS_LIMIT_IP' => '0'], 'TERMS_LIMIT_IP: must be a positive integer'];
        yield 'TERMS_LIMIT_DAY not a number' => [['TERMS_LIMIT_DAY' => 'abc'], 'TERMS_LIMIT_DAY: must be a positive integer'];
        yield 'timeout not a number' => [['X402_TIMEOUTS' => '{"analyze_terms":"abc"}'], 'X402_TIMEOUTS[analyze_terms] must be an integer'];
        yield 'duration too long' => [['X402_DURATIONS' => '{"analyze_terms":115}'], 'X402_DURATIONS[analyze_terms]'];
        yield 'a paid tool name with a typo' => [['X402_PAID_TOOLS' => 'wisdon,analyze_terms'], 'X402_PAID_TOOLS names "wisdon", which is no tool this server serves'];
        yield 'a price with a typo' => [['X402_PRICES' => '{"wisdon":"0.02"}'], 'X402_PRICES names "wisdon"'];
        yield 'X402_PRICE not a number' => [['X402_PRICE' => 'abc'], 'X402_PRICE: Not a price'];
        yield 'X402_PRICES not a number' => [['X402_PRICES' => '{"wisdom":"abc"}'], 'X402_PRICES[wisdom]: Not a price'];
        yield 'X402_PRICES zero' => [['X402_PRICES' => '{"wisdom":"0"}'], 'X402_PRICES[wisdom]: A price must be above zero'];
        yield 'a price for a free tool' => [['X402_PRICES' => '{"analyze_terms":"0.02","echo":"0.01"}'], 'X402_PRICES prices "echo", which is free'];
        yield 'a timeout typo, reported before the duration it breaks' => [['X402_TIMEOUTS' => '{"analyze_term":120}'], 'X402_TIMEOUTS names "analyze_term"'];
        yield 'a network that is no known network' => [['X402_NETWORK' => 'eip155:999999'], 'X402_NETWORK: "eip155:999999" is not a known network'];
        yield 'a network no registered scheme supports' => [['X402_NETWORK' => 'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1'], 'X402_NETWORK: "solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1" is supported by no registered scheme'];
        yield 'an offer our buyer refuses' => [['X402_BUYER_MAX_TIMEOUT' => '100'], 'analyze_terms offers 120 s, longer than our own buyer signs (100 s)'];
    }

    /** @param array<string, string> $env */
    #[DataProvider('bad')]
    public function testABadValueFailsWithItsName(array $env, string $message): void
    {
        $tester = $this->check($env);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString($message, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }
}
