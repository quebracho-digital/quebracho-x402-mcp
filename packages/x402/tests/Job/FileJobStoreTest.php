<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests\Job;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\Job\FileJobStore;
use Quebracho\X402\Job\IllegalTransition;
use Quebracho\X402\Job\Job;

final class FileJobStoreTest extends TestCase
{
    private string $dir;
    private FileJobStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/jobs-test-'.bin2hex(random_bytes(4));
        $this->store = new FileJobStore($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/{*,.lock}', \GLOB_BRACE) ?: []);
        @rmdir($this->dir);
    }

    public function testAJobIsFoundByIdAndBySettlementKey(): void
    {
        $job = $this->store->create(Job::settling('0xPayer', 'deposit-key', 'demo_start', ['text' => 'x'], 100));

        self::assertEquals($job, $this->store->get($job->id));
        self::assertEquals($job, $this->store->bySettlement('deposit-key'));
        self::assertNull($this->store->bySettlement('other-key'));
        self::assertNull($this->store->get(str_repeat('0', 32)));
    }

    public function testTheSameDepositGetsTheSameJob(): void
    {
        $first = $this->store->create(Job::settling('0xPayer', 'deposit-key', 'demo_start', ['text' => 'x'], 100));
        $again = $this->store->create(Job::settling('0xPayer', 'deposit-key', 'demo_start', ['text' => 'y'], 200));

        self::assertSame($first->id, $again->id);
        self::assertSame(100, $again->createdAt);
    }

    public function testAnIdThatIsNotOursIsNoJobAndNoPath(): void
    {
        self::assertNull($this->store->get('../../etc/passwd'));
        self::assertNull($this->store->get(''));
    }

    public function testATransitionIsWritten(): void
    {
        $job = $this->store->create(Job::settling('0xPayer', 'k', 'demo_start', ['text' => 'x'], 100)->to(Job::QUEUED, 100));
        $this->store->update($job->id, static fn (Job $j) => $j->to(Job::RUNNING, 150));

        self::assertSame(Job::RUNNING, $this->store->get($job->id)?->state);
    }

    public function testAnIllegalTransitionWritesNothing(): void
    {
        $job = $this->store->create(Job::settling('0xPayer', 'k', 'demo_start', ['text' => 'x'], 100)->to(Job::QUEUED, 100));
        try {
            $this->store->update($job->id, static fn (Job $j) => $j->to(Job::DELIVERED, 150));
            self::fail('queued > delivered was allowed');
        } catch (IllegalTransition) {
        }

        self::assertEquals($job, $this->store->get($job->id));
    }

    public function testAChangeCannotRewriteThePayer(): void
    {
        $job = $this->store->create(Job::settling('0xPayer', 'k', 'demo_start', ['text' => 'x'], 100)->to(Job::QUEUED, 100));

        $this->expectException(\LogicException::class);
        $this->store->update($job->id, static fn (Job $j) => new Job($j->id, '0xOther', $j->settlementKey, $j->tool, Job::RUNNING, $j->createdAt, 150));
    }

    public function testAChangeCannotRewriteTheToolOrTheSensitiveFlag(): void
    {
        $job = $this->store->create(Job::settling('0xPayer', 'k', 'demo_start', [], 100));
        foreach ([[$job->payer, 'other_start', false], [$job->payer, 'demo_start', true]] as [$payer, $tool, $sensitive]) {
            try {
                $this->store->update($job->id, static fn (Job $j) => new Job($j->id, $payer, $j->settlementKey, $tool, Job::QUEUED, $j->createdAt, 150, sensitive: $sensitive));
                self::fail('rewritten');
            } catch (\LogicException) {
            }
        }
        self::assertSame(Job::SETTLING, $this->store->get($job->id)?->state);
    }

    public function testOnlyASettlingJobCanBeDiscarded(): void
    {
        $settling = $this->store->create(Job::settling('0xPayer', 'k1', 'demo_start', ['text' => 'x'], 100));
        $paid = $this->store->create(Job::settling('0xPayer', 'k2', 'demo_start', ['text' => 'x'], 100)->to(Job::QUEUED, 100));

        self::assertTrue($this->store->discard($settling->id));
        self::assertNull($this->store->get($settling->id));
        self::assertNull($this->store->bySettlement('k1'), 'the index goes too: the same payment, retried, gets a fresh row');
        self::assertFalse($this->store->discard($paid->id), 'a paid job is never deleted');
        self::assertFalse($this->store->discard(str_repeat('0', 32)));
        self::assertSame(Job::QUEUED, $this->store->get($paid->id)?->state);
    }

    public function testAllListsEveryJobAndNothingElse(): void
    {
        self::assertSame([], iterator_to_array($this->store->all(), false), 'no directory yet');
        $a = $this->store->create(Job::settling('0xPayer', 'k1', 'demo_start', [], 100));
        $b = $this->store->create(Job::settling('0xPayer', 'k2', 'demo_start', [], 100));

        $ids = array_map(static fn (Job $j) => $j->id, iterator_to_array($this->store->all(), false));
        self::assertEqualsCanonicalizing([$a->id, $b->id], $ids);
    }

    public function testTheFilesAreTheOwnersOnly(): void
    {
        // A queued job holds the buyer's text.
        $job = $this->store->create(Job::settling('0xPayer', 'k', 'demo_start', ['text' => 'x'], 100));

        self::assertSame(0700, fileperms($this->dir) & 0777);
        self::assertSame(0600, fileperms($this->dir."/{$job->id}.json") & 0777);
    }

    public function testUpdatingAnUnknownJobThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->store->update(str_repeat('0', 32), static fn (Job $j) => $j);
    }

    /**
     * Two processes: the first holds the job while it moves it to running, the second asks for ready meanwhile.
     * The second must see running (it waited for the lock); without the lock it reads queued, throws, and the job stays running.
     */
    #[RequiresPhpExtension('pcntl')]
    public function testTwoWritersOnOneJobLoseNothing(): void
    {
        $job = $this->store->create(Job::settling('0xPayer', 'k', 'demo_start', ['text' => 'x'], 100)->to(Job::QUEUED, 100));
        $slow = $this->fork(fn () => $this->store->update($job->id, static function (Job $j): Job {
            usleep(300_000);

            return $j->to(Job::RUNNING, 150);
        }));
        usleep(100_000);
        $fast = $this->fork(fn () => $this->store->update($job->id, static fn (Job $j) => $j->to(Job::READY, 200, ['ok' => true])));

        self::assertSame([0, 0], [$this->wait($slow), $this->wait($fast)]);
        self::assertSame(Job::READY, $this->store->get($job->id)?->state);
    }

    /** Decision 7e: of several `_result` calls at once, one moves ready > delivering, the rest are refused. */
    #[RequiresPhpExtension('pcntl')]
    public function testOnlyOneOfManyDeliversARace(): void
    {
        $job = $this->store->create(Job::settling('0xPayer', 'k', 'demo_start', ['text' => 'x'], 100)->to(Job::QUEUED, 100));
        $this->store->update($job->id, static fn (Job $j) => $j->to(Job::RUNNING, 110)->to(Job::READY, 120, ['ok' => true]));

        $pids = [];
        for ($i = 0; $i < 8; ++$i) {
            // The pause between read and write is what a missing lock would let the others slip into.
            $pids[] = $this->fork(fn () => $this->store->update($job->id, static function (Job $j): Job {
                usleep(30_000);

                return $j->to(Job::DELIVERING, 200);
            }));
        }
        $codes = array_map($this->wait(...), $pids);
        sort($codes);

        self::assertSame([0, 1, 1, 1, 1, 1, 1, 1], $codes);
        self::assertSame(Job::DELIVERING, $this->store->get($job->id)?->state);
    }

    /**
     * Decision 7c: eight `_start` retries of one payment at once get one job. Without the lock around
     * "look up the key, else write", each child finds no key and writes its own job.
     */
    #[RequiresPhpExtension('pcntl')]
    public function testConcurrentCreatesOfOneDepositMakeOneJob(): void
    {
        $pids = [];
        for ($i = 0; $i < 8; ++$i) {
            $pids[] = $this->fork(fn () => $this->store->create(Job::settling('0xPayer', 'one-deposit', 'demo_start', [], 100)));
        }
        self::assertSame(array_fill(0, 8, 0), array_map($this->wait(...), $pids));

        self::assertCount(1, glob($this->dir.'/*.json') ?: []);
        self::assertNotNull($this->store->bySettlement('one-deposit'));
    }

    /** Runs $fn in a child: exit 0 when it returns, 1 on IllegalTransition, 2 on anything else. */
    private function fork(\Closure $fn): int
    {
        $pid = pcntl_fork();
        if (-1 === $pid) {
            self::fail('fork failed');
        }
        if (0 === $pid) {
            try {
                $fn();
                $code = 0;
            } catch (IllegalTransition) {
                $code = 1;
            } catch (\Throwable) {
                $code = 2;
            }
            // The code goes out as a signal, not exit(): exit would run PHPUnit's shutdown functions in the child.
            posix_kill(getmypid(), [\SIGTERM, \SIGUSR1, \SIGUSR2][$code]);
        }

        return $pid;
    }

    private function wait(int $pid): int
    {
        pcntl_waitpid($pid, $status);

        return match (pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : -1) {
            \SIGTERM => 0,
            \SIGUSR1 => 1,
            \SIGUSR2 => 2,
            default => 3,
        };
    }
}
