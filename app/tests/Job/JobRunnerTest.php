<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\FileJobStore;
use App\Job\Job;
use App\Job\JobFailed;
use App\Job\JobJanitor;
use App\Job\JobRunner;
use App\Job\JobWork;
use PHPUnit\Framework\TestCase;

final class JobRunnerTest extends TestCase
{
    private string $dir;
    private FileJobStore $jobs;
    /** @var \Closure(array): array */
    private \Closure $does;
    private int $runs = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/job-runner-'.bin2hex(random_bytes(4));
        $this->jobs = new FileJobStore($this->dir);
        $this->does = static fn (array $args) => ['echo' => $args['text']];
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function runner(): JobRunner
    {
        $work = new class($this) implements JobWork {
            public function __construct(private readonly JobRunnerTest $test)
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
                return $this->test->work($args);
            }
        };

        return new JobRunner($this->jobs, [$work], static fn () => 500);
    }

    /** @internal for the fake work */
    public function work(array $args): array
    {
        ++$this->runs;

        return ($this->does)($args);
    }

    private function job(string $state = Job::QUEUED, int $at = 100, string $tool = 'demo_start'): Job
    {
        $job = Job::settling('0xPayer', bin2hex(random_bytes(4)), $tool, ['text' => 'hi'], $at);

        return $this->jobs->create(Job::SETTLING === $state ? $job : $job->to(Job::QUEUED, $at));
    }

    public function testAQueuedJobRunsToReadyWithTheWorksAnswer(): void
    {
        $end = $this->runner()->run($this->job()->id);

        self::assertSame([Job::READY, ['echo' => 'hi'], 500], [$end->state, $end->result, $end->updatedAt]);
        self::assertSame(Job::READY, $this->jobs->get($end->id)?->state, 'written');
    }

    public function testAFailureFromTheListIsFiledWithItsReason(): void
    {
        $this->does = static fn () => throw new JobFailed('invalid_reply');

        self::assertSame([Job::FAILED_OURS, 'invalid_reply'], [($e = $this->runner()->run($this->job()->id))->state, $e->reason]);
    }

    public function testAnyOtherThrowIsOursToo(): void
    {
        // The input was checked before the deposit: a bug or a dead host is not the buyer's fault.
        $this->does = static fn () => throw new \TypeError('boom');

        self::assertSame('provider_error', $this->runner()->run($this->job()->id)->reason);
    }

    public function testASettlingJobNeverRuns(): void
    {
        // Its deposit is not confirmed (decision 7c): nothing runs from settling.
        $job = $this->job(Job::SETTLING);

        self::assertSame(Job::SETTLING, $this->runner()->run($job->id)->state);
        self::assertSame(0, $this->runs);
    }

    public function testAJobRunsOnce(): void
    {
        $id = $this->job()->id;
        $this->runner()->run($id);
        $this->runner()->run($id);

        self::assertSame(1, $this->runs, 'the second runner found it ready, not queued');
    }

    public function testAJobWithNoWorkFailsOurs(): void
    {
        self::assertSame([Job::FAILED_OURS, 'provider_error'], [($e = $this->runner()->run($this->job(tool: 'gone_start')->id))->state, $e->reason]);
    }

    public function testALateAnswerAfterTheJanitorGaveUpIsDropped(): void
    {
        $id = $this->job()->id;
        $this->does = function (): array {
            // While the work runs, the janitor finds it stale.
            (new JobJanitor($this->jobs))->sweep(10_000, 60);

            return ['late' => true];
        };

        $end = $this->runner()->run($id);
        self::assertSame([Job::FAILED_OURS, 'our_timeout', null], [$end->state, $end->reason, $end->result]);
    }

    public function testTheJanitorFailsOnlyStaleQueuedOrRunningJobs(): void
    {
        $stale = [$this->job(at: 100), $this->jobs->update($this->job(at: 100)->id, static fn (Job $j) => $j->to(Job::RUNNING, 100))];
        $fresh = $this->job(at: 950);
        $settling = $this->job(Job::SETTLING, 100);
        $delivering = $this->jobs->update($this->job(at: 100)->id, static fn (Job $j) => $j->to(Job::RUNNING, 100)->to(Job::READY, 100, ['x' => 1])->to(Job::DELIVERING, 100));

        $failed = (new JobJanitor($this->jobs))->sweep(1000, 300);

        self::assertEqualsCanonicalizing([$stale[0]->id, $stale[1]->id], $failed);
        foreach ($stale as $job) {
            self::assertSame([Job::FAILED_OURS, 'our_timeout'], [$this->jobs->get($job->id)?->state, $this->jobs->get($job->id)?->reason]);
        }
        self::assertSame(Job::QUEUED, $this->jobs->get($fresh->id)?->state);
        self::assertSame(Job::SETTLING, $this->jobs->get($settling->id)?->state, "the reconciler's");
        self::assertSame(Job::DELIVERING, $this->jobs->get($delivering->id)?->state, 'its payment may be on chain');
        self::assertSame([], (new JobJanitor($this->jobs))->sweep(1000, 300), 'a second sweep has nothing to do');
    }
}
