<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Log\LoggerInterface;
use Quebracho\X402\Reconciler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Corrects the open payments of the ledger with the chain (Quebracho\X402\Reconciler). Meant for cron, every 5 minutes.
 * Always exits 0: a key that could not be resolved is in the table (needs_review, rpc_error) and in the log.
 */
#[AsCommand(name: 'app:x402:reconcile', description: 'Correct the x402 payments stuck in settling or pending with what the chain says')]
final class X402ReconcileCommand
{
    public function __construct(
        private readonly Reconciler $reconciler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('How many open payments to look at')] int $limit = 100): int
    {
        $results = $this->reconciler->reconcile(max(1, $limit));
        if ([] === $results) {
            $io->text('No open x402 payments: nothing to reconcile.');

            return Command::SUCCESS;
        }
        foreach ($results as $r) {
            if (\in_array($r['result'], ['needs_review', 'rpc_error'], true)) {
                $this->logger->warning('x402 reconcile: {key} {result}: {detail}', $r);
            }
        }
        $io->table(['key', 'was', 'result', 'detail'], array_map(static fn (array $r): array => [substr($r['key'], 0, 16), $r['status'], $r['result'], $r['detail']], $results));

        return Command::SUCCESS;
    }
}
