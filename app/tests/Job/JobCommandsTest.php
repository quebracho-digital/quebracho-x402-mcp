<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Command\JobJanitorCommand;
use App\Command\JobReconcileCommand;
use App\EventListener\JobTerminate;
use App\X402\JobSlots;
use Quebracho\X402\FileLedger;
use Quebracho\X402\Job\FileJobStore;
use Quebracho\X402\Job\Job;
use Quebracho\X402\Job\JobJanitor;
use Quebracho\X402\Job\JobPayments;
use Quebracho\X402\Job\JobReconciler;
use Quebracho\X402\Job\JobRunner;
use Quebracho\X402\Job\JobWork;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * The cron entry points of job mode (the commands) and the kernel.terminate trigger, wired as the app wires
 * them. The flow itself is JobFlowTest; the janitor's own steps are JobRunnerTest.
 */
final class JobCommandsTest extends KernelTestCase
{
    private string $dir;
    private FileJobStore $jobs;
    private FileLedger $ledger;
    private int $runs = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/job-commands-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $this->jobs = new FileJobStore($this->dir.'/jobs');
        $this->ledger = new FileLedger($this->dir.'/ledger');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function queued(int $at): Job
    {
        $job = Job::settling('0xPayer', bin2hex(random_bytes(4)), 'demo_start', ['text' => 'hi'], $at);

        return $this->jobs->create($job->to(Job::QUEUED, $at));
    }

    private function reconciler(): JobReconciler
    {
        return new JobReconciler($this->jobs, new JobPayments($this->ledger), $this->ledger);
    }

    private function runner(): JobRunner
    {
        $work = new class($this) implements JobWork {
            public function __construct(private readonly JobCommandsTest $test)
            {
            }

            public static function start(): string
            {
                return 'demo_start';
            }

            public function check(array $args): ?string
            {
                return null;
            }

            public function refuse(): ?string
            {
                return null;
            }

            public function run(array $args): array
            {
                $this->test->ran();

                return ['ok' => true];
            }
        };

        return new JobRunner($this->jobs, [$work], static fn () => time());
    }

    /** @internal for the fake work */
    public function ran(): void
    {
        ++$this->runs;
    }

    public function testTheJanitorLeavesAnOrphanAloneWhenEverySlotIsTaken(): void
    {
        $orphan = $this->queued(time() - JobJanitor::ORPHAN_AFTER);
        $slots = new JobSlots(new LockFactory(new FlockStore($this->dir)), 1);
        $held = $slots->take();
        self::assertNotNull($held, 'the test holds the only slot, as a web request would');
        $out = new BufferedOutput();
        try {
            $code = (new JobJanitorCommand(new JobJanitor($this->jobs), $this->reconciler(), $this->runner(), $slots))(new SymfonyStyle(new ArrayInput([]), $out));
        } finally {
            $held->release();
        }

        self::assertSame(0, $code);
        self::assertSame(0, $this->runs, 'the cron never runs more jobs at once than JOB_SLOTS allows');
        self::assertSame(Job::QUEUED, $this->jobs->get($orphan->id)?->state, 'it waits for the next minute');
        self::assertStringContainsString('0 ran.', $out->fetch());
    }

    public function testTheJanitorClampsAStaleLimitBelowAMinute(): void
    {
        // A job untouched for two minutes is not abandoned by --stale=1: the floor is 60 s, so it is
        // not failed behind a runner that is still working.
        $job = $this->queued(time() - 20);
        $slots = new JobSlots(new LockFactory(new FlockStore($this->dir)), 1);
        $out = new BufferedOutput();
        (new JobJanitorCommand(new JobJanitor($this->jobs), $this->reconciler(), $this->runner(), $slots))(new SymfonyStyle(new ArrayInput([]), $out), 1);

        self::assertSame(Job::QUEUED, $this->jobs->get($job->id)?->state);
        self::assertStringContainsString('0 stale job(s) failed.', $out->fetch());
    }

    public function testTheReconcileCommandSaysWhenNothingWaitsOnAPayment(): void
    {
        $out = new BufferedOutput();
        $code = (new JobReconcileCommand($this->reconciler()))(new SymfonyStyle(new ArrayInput([]), $out));

        self::assertSame(0, $code);
        self::assertStringContainsString('No job is waiting on a payment.', $out->fetch());
    }

    public function testTheReconcileCommandListsASettlingJobItCannotYetResolve(): void
    {
        // The ledger knows nothing of this deposit and no chain is asked: it stays settling, and the
        // command shows why instead of hiding the row.
        $job = $this->jobs->create(Job::settling('0xPayer', 'key-'.bin2hex(random_bytes(3)), 'demo_start', ['text' => 'hi'], time() - 600));
        $out = new BufferedOutput();
        $code = (new JobReconcileCommand($this->reconciler()))(new SymfonyStyle(new ArrayInput([]), $out));
        $display = $out->fetch();

        self::assertSame(0, $code, 'always 0: a person reads the table');
        self::assertStringContainsString($job->id, $display);
        self::assertStringContainsString(Job::SETTLING, $display);
        self::assertSame(Job::SETTLING, $this->jobs->get($job->id)?->state, 'looking changes nothing');
    }

    public function testTheTerminateListenerIsRegisteredOnKernelTerminate(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');

        $classes = array_map(static fn ($l) => \is_array($l) ? $l[0]::class : (\is_object($l) ? $l::class : ''), $dispatcher->getListeners(KernelEvents::TERMINATE));
        self::assertContains(JobTerminate::class, $classes, 'without it a paid deposit would queue a job nothing runs until the cron');
    }
}
