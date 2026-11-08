<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * What a ListSync did: how many addresses the file now holds, the date of that list (OFAC's `published`, a
 * text list's `# updated:`), and whether the download replaced the file or the file was kept because it held
 * a younger list (a mirror or a cache that lags). A cron logs it; `replaced: false` run after run means the
 * source stopped moving.
 */
final class SyncResult
{
    public function __construct(
        public readonly int $count,
        public readonly string $updated,
        public readonly bool $replaced,
    ) {
    }
}
