<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * OFAC's SDN.CSV from the Sanctions List Service to the snapshot OfacSdn reads (OfacSdn::sync).
 */
final class OfacSdnSync implements ListSync
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
    ) {
    }

    public function sync(string $file): SyncResult
    {
        return OfacSdn::sync($this->http, $this->requests, $file);
    }
}
