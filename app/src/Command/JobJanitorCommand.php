<?php

declare(strict_types=1);

namespace App\Command;

use App\Job\JobJanitor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * From cron, every few minutes: `bin/console app:jobs:janitor`. Gives up on queued or running jobs that
 * nobody touched for --stale seconds (failed_ours / our_timeout): their `_result` is then free.
 */
#[AsCommand(name: 'app:jobs:janitor', description: 'Fail (failed_ours, our_timeout) the queued or running jobs nobody is working on')]
final class JobJanitorCommand
{
    public function __construct(private readonly JobJanitor $janitor)
    {
    }

    /**
     * @param int $stale seconds without a change: longer than any work (the model call stops at 40 s)
     */
    public function __invoke(SymfonyStyle $io, #[Option('Seconds without a change before a queued or running job counts as abandoned (at least 60)')] int $stale = 300): int
    {
        $failed = $this->janitor->sweep(time(), max(60, $stale));
        $io->writeln(\sprintf('%d stale job(s) failed%s', \count($failed), [] === $failed ? '.' : ': '.implode(', ', $failed)));

        return 0;
    }
}
