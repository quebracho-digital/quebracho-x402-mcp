<?php

declare(strict_types=1);

namespace App\Command;

use Quebracho\X402\Stop;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The stop switch, by hand: `app:x402:stop on "why"` pauses every payment of this deployment, `off` resumes,
 * `status` says where it is, and exits 1 while paused (0 while running), so a monitor or a script can ask:
 * `bin/console app:x402:stop status || alert`. It is the library's Quebracho\X402\Stop, a flag file (X402_STOP_FILE, by default
 * var/x402-stop/paused): the seller's middleware and the buyer's client are given the same instance
 * (services.yaml), so while the file exists.
 *
 *   - a paid tools/call is answered payments_paused (JSON-RPC -32000) before anything is verified, claimed,
 *     run or charged; free tools and tools/list still answer;
 *   - app:ask signs nothing, and reports the offer as refused.
 *
 * What it cannot do: recall a payment already signed or a settle already sent, and a payment the ledger
 * already holds is still delivered (it was paid). Jobs already paid for keep running.
 *
 * With no PHP at hand, `touch` on the file does the same as `on`. Several hosts: point X402_STOP_FILE at a
 * path they share, or run the command on each.
 */
#[AsCommand(name: 'app:x402:stop', description: 'Pause or resume every x402 payment: on [why] | off | status (exits 1 while paused)')]
final class X402StopCommand
{
    public function __construct(private readonly Stop $stop)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('on, off or status', suggestedValues: ['on', 'off', 'status'])] string $action = 'status',
        #[Argument('Why (kept in the flag file for whoever reads it; never sent to buyers)')] string $why = '',
    ): int {
        switch ($action) {
            case 'on':
                try {
                    $this->stop->engage($why);
                } catch (\RuntimeException $e) {
                    // The library refuses a flag file it cannot trust (a link, somebody else's file, a directory
                    // others can write to). Say what is true now: the switch may or may not read as engaged.
                    $io->error([$e->getMessage(), $this->stop->engaged() ? 'Payments ARE paused all the same: something is at the flag path.' : 'Payments are NOT paused.']);

                    return Command::FAILURE;
                }
                $io->warning('Payments are paused: nothing new is sold or bought until "app:x402:stop off".');
                break;
            case 'off':
                try {
                    $this->stop->release();
                } catch (\RuntimeException $e) {
                    $io->error([$e->getMessage(), 'Payments are still paused.']);

                    return Command::FAILURE;
                }
                if ($this->stop->engaged()) {
                    // Fails closed: a flag directory this user cannot search still counts as engaged.
                    $io->error('The flag file is gone but the switch still reads as engaged: check the permissions of its directory.');

                    return Command::FAILURE;
                }
                $io->success('Payments resumed.');
                break;
            case 'status':
                if ($this->stop->engaged()) {
                    $io->warning(trim('Payments are PAUSED. '.($this->stop->note() ?? '')));

                    // Not an error of the command: the answer, for whoever asks from a script.
                    return Command::FAILURE;
                }
                $io->writeln('Payments are running.');
                break;
            default:
                $io->error("Unknown action \"$action\": on, off or status.");

                return Command::INVALID;
        }

        return Command::SUCCESS;
    }
}
