<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * A plain-text address list another site publishes at an HTTPS URL, to the file AddressList reads
 * (AddressList::sync).
 */
final class AddressListSync implements ListSync
{
    /**
     * @param int $min fewer addresses than this is not the list: the old file is kept
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly string $url,
        private readonly int $min = 1,
    ) {
    }

    public function sync(string $file): int
    {
        return AddressList::sync($this->http, $this->requests, $this->url, $file, $this->min);
    }
}
