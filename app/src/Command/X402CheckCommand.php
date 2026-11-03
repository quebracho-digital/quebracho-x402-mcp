<?php

declare(strict_types=1);

namespace App\Command;

use App\X402\Pricing;
use Quebracho\X402\PayingMcpClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Deploy step: builds what the x402 env configures, which otherwise happens only
 * on the first /mcp request (a 500) or the first app:ask, and exits 1 on any bad value.
 */
#[AsCommand(name: 'app:x402:check', description: 'Check the x402 env (prices, timeouts, limits, buyer budget) before going live')]
final class X402CheckCommand
{
    private const LIMITS = ['MCP_LIMIT_IP', 'MCP_LIMIT_GLOBAL', 'TERMS_LIMIT_IP', 'TERMS_LIMIT_DAY'];

    /**
     * @param array<string, string> $limits raw env, by name
     */
    public function __construct(
        #[AutowireServiceClosure(Pricing::class)] private readonly \Closure $pricing,
        #[AutowireServiceClosure(PayingMcpClient::class)] private readonly \Closure $buyer,
        #[AutowireServiceClosure('limiter.mcp_ip')] private readonly \Closure $mcpIp,
        #[AutowireServiceClosure('limiter.mcp_global')] private readonly \Closure $mcpGlobal,
        #[AutowireServiceClosure('limiter.terms_ip')] private readonly \Closure $termsIp,
        #[AutowireServiceClosure('limiter.terms_day')] private readonly \Closure $termsDay,
        #[Autowire([
            'MCP_LIMIT_IP' => '%env(default::MCP_LIMIT_IP)%',
            'MCP_LIMIT_GLOBAL' => '%env(default::MCP_LIMIT_GLOBAL)%',
            'TERMS_LIMIT_IP' => '%env(default::TERMS_LIMIT_IP)%',
            'TERMS_LIMIT_DAY' => '%env(default::TERMS_LIMIT_DAY)%',
        ])] private readonly array $limits,
        #[Autowire('%env(default::X402_TIMEOUTS)%')] private readonly ?string $timeouts,
        #[Autowire('%env(default::X402_BUYER_MAX_TIMEOUT)%')] private readonly ?string $buyerMax,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $errors = [];
        $check = static function (string $what, \Closure $build) use (&$errors, $io): void {
            try {
                $build();
                $io->writeln("<info>ok</info>   $what");
            } catch (\Throwable $e) {
                $errors[] = "$what: {$e->getMessage()}";
            }
        };

        foreach (self::LIMITS as $name) {
            // A sliding window takes 0 and then refuses everything; a string is not a number of calls.
            $check($name, fn () => preg_match('/^[1-9][0-9]*$/', (string) ($this->limits[$name] ?? ''))
                ?: throw new \InvalidArgumentException(\sprintf('must be a positive integer, got "%s".', $this->limits[$name] ?? '')));
        }
        foreach (['mcp_ip' => $this->mcpIp, 'mcp_global' => $this->mcpGlobal, 'terms_ip' => $this->termsIp, 'terms_day' => $this->termsDay] as $id => $factory) {
            $check("limiter $id", fn () => $factory()->create('app:x402:check'));
        }
        // Pricing's constructor prices every paid tool and refuses a name that is no tool.
        $check('Pricing (X402_PAID_TOOLS, X402_PRICE(S), X402_PAY_TO, X402_NETWORK, X402_TIMEOUTS, X402_DURATIONS, #[PaidJob])', fn () => ($this->pricing)());
        $check('buyer (X402_BUDGETS, X402_MAX_PER_PAYMENT, X402_BUYER_KEY, X402_BUYER_MAX_TIMEOUT)', fn () => ($this->buyer)());
        $check('X402_TIMEOUTS within X402_BUYER_MAX_TIMEOUT', function (): void {
            foreach ((array) json_decode((string) $this->timeouts, true) as $tool => $seconds) {
                if ((int) $seconds > (int) $this->buyerMax) {
                    throw new \InvalidArgumentException("$tool offers $seconds s, longer than our own buyer signs ({$this->buyerMax} s): app:ask would refuse it.");
                }
            }
        });

        if ([] !== $errors) {
            $io->error($errors);

            return Command::FAILURE;
        }
        $io->success('x402 env checked.');

        return Command::SUCCESS;
    }
}
