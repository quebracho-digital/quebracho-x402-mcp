<?php

declare(strict_types=1);

namespace App\Command;

use App\Mcp\AnalyzeTermsTool;
use App\X402\Receipts;
use Quebracho\X402\Networks;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\McpBundle\Client\McpClientInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * An agent that discovers the MCP server's tools, pays the paid ones with
 * x402 in band, and answers with them.
 *
 * The buyer side of the library, and there is no payment code in this class. The MCP client `buyer`
 * (config/packages/mcp.yaml) sends its requests through Quebracho\X402\PayingMcpClient, a PSR-18 client
 * (config/services.yaml, BUYER): a tools/call answered with an x402 PaymentRequired is paid within the
 * budget (X402_BUDGETS, X402_MAX_PER_PAYMENT), only to an allowed payTo (X402_PAYTO_ALLOW, OFAC), and sent
 * again; the agent only ever sees the tool's answer. What was paid, and what was refused and why, reaches
 * App\X402\Receipts through the client's $onPaid and $onRefused closures, and is printed below.
 */
#[AsCommand(name: 'app:ask', description: 'Ask the agent; it discovers, pays (x402) and calls MCP tools')]
final class AskCommand
{
    public function __construct(
        #[Target('default')] private readonly AgentInterface $agent,
        #[Target('huggingface')] private readonly AgentInterface $huggingface,
        #[Target('buyer')] private readonly McpClientInterface $buyer,
        private readonly Receipts $receipts,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('What to ask the agent')] string $question = '',
        #[Option('Call this MCP tool directly, without the LLM')] ?string $tool = null,
        #[Option('Use Hugging Face Inference Providers (HF_TOKEN, HF_MODEL) instead of the LLM_* server')] bool $hf = false,
    ): int {
        $server = $this->buyer->get('self');
        $io->text('Tools on the server: '.implode(', ', array_map(static fn ($t) => $t->name, $server->getTools())));
        try {
            $io->text('Tools on Ombú: '.implode(', ', array_map(static fn ($t) => $t->name, $this->buyer->get('ombu')->getTools())));
        } catch (\Throwable $e) {
            $io->text('Ombú unreachable, continuing without it: '.$e->getMessage());
        }

        if (null !== $tool) {
            $result = $server->callTool($tool, []);
            $answer = implode("\n", array_map(static fn ($c) => $c->text ?? json_encode($c), $result->content));
        } else {
            $answer = ($hf ? $this->huggingface : $this->agent)->call(new MessageBag(Message::ofUser($question)))->getContent();
        }

        foreach ($this->receipts->all as ['tool' => $name, 'req' => $req, 'receipt' => $receipt]) {
            $usd = (int) $req['amount'] / 10 ** Networks::get($req['network'])['decimals'];
            $io->text(\sprintf('Paid tool "%s": %s USDC (%s atomic) on %s to %s, %s', $name, $usd, $req['amount'], $req['network'], $req['payTo'], match (true) {
                true !== $receipt['success'] => 'NOT settled',
                '' === ($receipt['transaction'] ?? '') => 'mock: verified, nothing moved on chain',
                default => 'settled in '.$receipt['transaction'],
            }));
            $io->text('Receipt: '.json_encode($receipt, \JSON_UNESCAPED_SLASHES));
            if ('analyze_terms' === $name) {
                // The agent summarises the tool's answer and drops the notice; the user must still see it.
                $io->warning(AnalyzeTermsTool::NOTICE);
            }
        }
        foreach ($this->receipts->refused as ['req' => $req, 'reason' => $reason]) {
            $io->text(\sprintf('Not paid: %s atomic on %s to %s (%s)', $req['amount'] ?? '?', $req['network'] ?? '?', $req['payTo'] ?? '?', $reason));
        }
        if ([] !== array_filter($this->receipts->all, static fn (array $r) => true !== $r['receipt']['success'])) {
            $io->warning('A settlement failed. The public testnet facilitator is shared and fails about one in six on its side: nothing was delivered or charged to you. Run the command again.');
        }
        if ([] === $this->receipts->all) {
            $io->text('Nothing paid.');
        }
        $io->section('Answer');
        $io->writeln(\is_string($answer) ? $answer : (string) json_encode($answer));

        return Command::SUCCESS;
    }
}
