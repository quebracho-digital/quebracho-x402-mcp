<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

/**
 * Jobs for one host: `<id>.json` per job, and `<sha256(settlementKey)>.key` holding its id. Every write
 * holds a flock on `.lock` and goes through a rename, so a reader never sees half a job and two writers
 * never interleave. Several hosts need a shared store (SQLite on a shared disk, a DB).
 *
 * Files are 0600 in a 0700 directory: a queued job holds the buyer's text.
 */
final class FileJobStore implements JobStore
{
    public function __construct(private readonly string $dir)
    {
    }

    public function create(Job $job): Job
    {
        return $this->locked(function () use ($job): Job {
            // Under the lock: two `_start` retries of one payment at once still get one job.
            $existing = $this->bySettlement($job->settlementKey);
            if (null !== $existing) {
                return $existing;
            }
            // The job before its index: a crash in between leaves a job nobody finds by its key (all() still
            // lists it), never an index to nothing.
            $this->write($this->path($job->id), json_encode($job->toArray(), \JSON_THROW_ON_ERROR));
            $this->write($this->keyPath($job->settlementKey), $job->id);

            return $job;
        });
    }

    public function get(string $id): ?Job
    {
        // The id names a file: anything but our own hex is not a job (and not a path).
        if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
            return null;
        }
        $raw = @file_get_contents($this->path($id));
        $row = false === $raw ? null : json_decode($raw, true);

        return \is_array($row) ? Job::fromArray($row) : null;
    }

    public function bySettlement(string $settlementKey): ?Job
    {
        $id = @file_get_contents($this->keyPath($settlementKey));

        return false === $id ? null : $this->get($id);
    }

    public function update(string $id, \Closure $change): Job
    {
        return $this->locked(function () use ($id, $change): Job {
            $job = $this->get($id) ?? throw new \OutOfBoundsException("No job $id.");
            $next = $change($job);
            if ([$next->id, $next->payer, $next->settlementKey, $next->tool, $next->createdAt, $next->sensitive, $next->depositRef] !== [$job->id, $job->payer, $job->settlementKey, $job->tool, $job->createdAt, $job->sensitive, $job->depositRef]) {
                throw new \LogicException("Job $id: a change may not rewrite who paid, for what, or when.");
            }
            $this->write($this->path($id), json_encode($next->toArray(), \JSON_THROW_ON_ERROR));

            return $next;
        });
    }

    public function discard(string $id): bool
    {
        return $this->locked(function () use ($id): bool {
            $job = $this->get($id);
            if (Job::SETTLING !== $job?->state) {
                return false;
            }
            // The index first: a crash in between leaves a job no retry finds, which all() still lists.
            @unlink($this->keyPath($job->settlementKey));

            return @unlink($this->path($id));
        });
    }

    public function all(): iterable
    {
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            $job = $this->get(basename($file, '.json'));
            if (null !== $job) {
                yield $job;
            }
        }
    }

    private function path(string $id): string
    {
        return "{$this->dir}/$id.json";
    }

    private function keyPath(string $settlementKey): string
    {
        return $this->dir.'/'.hash('sha256', $settlementKey).'.key';
    }

    /**
     * @template T
     *
     * @param \Closure(): T $fn
     *
     * @return T
     */
    private function locked(\Closure $fn): mixed
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create the job directory {$this->dir}.");
        }
        $lock = @fopen($this->dir.'/.lock', 'c');
        if (false === $lock || !flock($lock, \LOCK_EX)) {
            false === $lock || fclose($lock);

            throw new \RuntimeException("Cannot lock the job directory {$this->dir}.");
        }
        try {
            return $fn();
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Temp file, fsync, rename: a reader sees the old file or the new one, never half of one, and the bytes
     * are on disk before the name points at them (a `settling` row must outlive a crash right after the
     * settle). ponytail: the directory itself is not fsynced (PHP cannot open one for writing), so a power
     * cut can still lose the newest rename on some filesystems; a process crash cannot.
     */
    private function write(string $file, string $content): void
    {
        $tmp = @tempnam($this->dir, 'put');
        $out = false === $tmp ? false : @fopen($tmp, 'w');
        $ok = false !== $out && \strlen($content) === @fwrite($out, $content) && fsync($out);
        false === $out || fclose($out);
        if (!$ok || false === $tmp || !@rename($tmp, $file)) {
            false === $tmp || @unlink($tmp);

            throw new \RuntimeException("Cannot write the job file $file.");
        }
    }
}
