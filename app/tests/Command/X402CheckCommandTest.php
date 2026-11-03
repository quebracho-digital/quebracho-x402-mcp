<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

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

    public function testAnOfferExactlyAsLongAsOurBuyerSignsPasses(): void
    {
        self::assertSame(0, $this->check(['X402_BUYER_MAX_TIMEOUT' => '120'])->getStatusCode());
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
