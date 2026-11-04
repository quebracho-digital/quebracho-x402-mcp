<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * A PaymentLedger for one host: one JSON file per key, named by the key's SHA-256.
 *
 * Claims are serialised by flock on a lock file in the directory, so an expired file is replaced
 * without another process slipping a claim in between. Writes go through a rename, so a reader
 * never sees half a state. Several hosts need a shared store (a DB unique key, Redis SET NX).
 */
final class FileLedger implements PaymentLedger
{
    public function __construct(private readonly string $dir)
    {
    }

    public function claim(string $key, int $ttl): bool
    {
        $lock = $this->lock();
        if (null === $lock) {
            // Cannot serialise, so cannot prove it is fresh: refuse.
            return false;
        }
        try {
            $file = $this->path($key);
            if (null !== $this->read($file)) {
                return false;
            }
            // Absent or expired: replace it.
            @unlink($file);
            $handle = @fopen($file, 'x');
            if (false === $handle) {
                return false;
            }
            fwrite($handle, $this->encode(['status' => 'claimed'], $ttl));
            fclose($handle);

            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function get(string $key): ?array
    {
        return $this->read($this->path($key));
    }

    public function put(string $key, array $state, int $ttl): void
    {
        $this->ensureDir();
        $file = $this->path($key);
        $tmp = tempnam($this->dir, 'put');
        if (false === $tmp) {
            throw new \RuntimeException("Cannot write the x402 ledger in {$this->dir}.");
        }
        file_put_contents($tmp, $this->encode($state, $ttl));
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write the x402 ledger entry {$file}.");
        }
    }

    public function release(string $key): void
    {
        @unlink($this->path($key));
    }

    /**
     * Deletes expired states; returns how many.
     */
    public function prune(): int
    {
        $n = 0;
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            if (null === $this->read($file) && @unlink($file)) {
                ++$n;
            }
        }

        return $n;
    }

    private function path(string $key): string
    {
        return $this->dir.'/'.hash('sha256', $key).'.json';
    }

    /**
     * @return ?array<string, mixed> NULL when the file is absent, unreadable or expired
     */
    private function read(string $file): ?array
    {
        $raw = @file_get_contents($file);
        $data = false === $raw ? null : json_decode($raw, true);
        if (!is_array($data) || !is_int($data['expires'] ?? null) || !is_array($data['state'] ?? null) || $data['expires'] <= time()) {
            return null;
        }

        return $data['state'];
    }

    private function encode(array $state, int $ttl): string
    {
        return json_encode(['expires' => time() + $ttl, 'state' => $state], JSON_THROW_ON_ERROR);
    }

    /**
     * @return resource|null
     */
    private function lock()
    {
        $this->ensureDir();
        $lock = @fopen($this->dir.'/.lock', 'c');

        return false !== $lock && flock($lock, LOCK_EX) ? $lock : null;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create the x402 ledger directory {$this->dir}.");
        }
    }
}
