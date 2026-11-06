<?php

declare(strict_types=1);

namespace App\Command;

use App\X402\JobReconciler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step 1 of app:jobs:janitor on its own, with every job it looked at: by hand, to see what is stuck and
 * why. `unknown` rows wait on the ledger (run app:x402:reconcile first) or on the chain (a node that did
 * not answer, a signature still valid); a `settling` row unknown for a day is App\X402\Reconciler's
 * needs_review, and its payment is reconciled by a person.
 */
#[AsCommand(name: 'app:jobs:reconcile', description: 'Move on the settling or delivering jobs whose payment is now known to have settled or not')]
final class JobReconcileCommand
{
    public function __construct(private readonly JobReconciler $reconciler)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $rows = $this->reconciler->reconcile(time());
        if ([] === $rows) {
            $io->text('No job is waiting on a payment.');

            return Command::SUCCESS;
        }
        $io->table(['job', 'was', 'result'], array_map(static fn (array $r): array => [$r['job'], $r['was'], $r['result']], $rows));

        return Command::SUCCESS;
    }
}
