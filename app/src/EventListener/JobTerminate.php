<?php

declare(strict_types=1);

namespace App\EventListener;

use Quebracho\X402\Job\JobDesk;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The job runner's trigger (decision 3): kernel.terminate runs after Response::send, which under PHP-FPM
 * has already called fastcgi_finish_request, so the buyer has its jobId while the work runs. Only a job
 * whose row is durable (queued, written by JobDeposit::queue) is ever run. Messenger can replace this later
 * behind the same JobRunner::run.
 *
 * The one call job mode needs from the framework: JobDesk::afterResponse(), once per request, after the
 * response went out. It runs the job this request queued and clears the desk's per-request state.
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
final class JobTerminate
{
    public function __construct(private readonly JobDesk $desk)
    {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $this->desk->afterResponse();
    }
}
