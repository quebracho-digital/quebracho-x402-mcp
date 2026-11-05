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

    /**
     * @throws LedgerUnavailableException when the directory or its lock cannot be used (I/O), nothing claimed
     */
    public function claim(string $key, int $ttl): bool
    {
        $lock = $this->lock();
        try {
            $file = $this->path($key);
            if (null !== $this->read($file)) {
                return false;
            }
            // Absent or expired: replace it. The lock keeps other claims out, so the rename is the claim; a write
            // that fails throws, so a claim is never reported taken without its file.
            $this->write($file, $this->encode(['status' => 'claimed'], $ttl));

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

    /**
     * @throws LedgerUnavailableException on an I/O failure; the state is unchanged
     */
    public function put(string $key, array $state, int $ttl): void
    {
        $this->write($this->path($key), $this->encode($state, $ttl));
    }

    /**
     * @throws LedgerUnavailableException when a file stays (I/O); an absent one is already released
     */
    public function release(string $key): void
    {
        $this->remove($this->path($key));
        $this->close($key);
    }

    /**
     * One `<sha256>.open` file per key, next to its state: {expires, key, ref}.
     *
     * @throws LedgerUnavailableException on an I/O failure
     */
    public function open(string $key, array $ref, int $ttl): void
    {
        $file = $this->path($key, 'open');
        $before = $this->readOpen($file);
        $ref['opened'] = $before['ref']['opened'] ?? (is_int($ref['opened'] ?? null) ? $ref['opened'] : time());
        $this->write($file, json_encode(['expires' => time() + $ttl, 'key' => $key, 'ref' => $ref], JSON_THROW_ON_ERROR));
    }

    public function openKeys(int $limit): array
    {
        $out = [];
        foreach (glob($this->dir.'/*.open') ?: [] as $file) {
            if (count($out) >= $limit) {
                break;
            }
            $entry = $this->readOpen($file);
            if (null === $entry) {
                $this->remove($file);
                continue;
            }
            $out[$entry['key']] = $entry['ref'];
        }

        return $out;
    }

    /**
     * @throws LedgerUnavailableException when the file stays (I/O)
     */
    public function close(string $key): void
    {
        $this->remove($this->path($key, 'open'));
    }

    /**
     * Deletes expired states; returns how many.
     */
    public function prune(): int
    {
        $n = 0;
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            if (null === $this->read($file)) {
                $this->remove($file);
                ++$n;
            }
        }

        return $n;
    }

    private function path(string $key, string $extension = 'json'): string
    {
        return $this->dir.'/'.hash('sha256', $key).'.'.$extension;
    }

    /**
     * Writes through a rename, so a reader never sees half a file.
     *
     * @throws LedgerUnavailableException on an I/O failure; the file is unchanged
     */
    private function write(string $file, string $content): void
    {
        $this->ensureDir();
        $tmp = @tempnam($this->dir, 'put');
        if (false === $tmp) {
            throw new LedgerUnavailableException("Cannot write the x402 ledger in {$this->dir}.");
        }
        if (strlen($content) !== @file_put_contents($tmp, $content) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new LedgerUnavailableException("Cannot write the x402 ledger entry {$file}.");
        }
    }

    /**
     * Deletes $file; one already gone (another process, or never written) is not an error.
     *
     * @throws LedgerUnavailableException when the file is still there
     */
    private function remove(string $file): void
    {
        if (!@unlink($file) && file_exists($file)) {
            throw new LedgerUnavailableException("Cannot delete the x402 ledger entry {$file}.");
        }
    }

    /**
     * @return ?array{key: string, ref: array<string, mixed>} NULL when absent, unreadable or expired
     */
    private function readOpen(string $file): ?array
    {
        $raw = @file_get_contents($file);
        $data = false === $raw ? null : json_decode($raw, true);
        if (!is_array($data) || !is_int($data['expires'] ?? null) || !is_string($data['key'] ?? null) || !is_array($data['ref'] ?? null) || $data['expires'] <= time()) {
            return null;
        }

        return ['key' => $data['key'], 'ref' => $data['ref']];
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
     * @return resource
     *
     * @throws LedgerUnavailableException when the lock file cannot be opened or locked
     */
    private function lock()
    {
        $this->ensureDir();
        $lock = @fopen($this->dir.'/.lock', 'c');
        if (false === $lock) {
            throw new LedgerUnavailableException("Cannot open the x402 ledger lock in {$this->dir}.");
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);

            throw new LedgerUnavailableException("Cannot lock the x402 ledger in {$this->dir}.");
        }

        return $lock;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new LedgerUnavailableException("Cannot create the x402 ledger directory {$this->dir}.");
        }
    }
}
