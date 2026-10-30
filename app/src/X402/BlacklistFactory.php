<?php

declare(strict_types=1);

namespace App\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Quebracho\X402\TokenBlacklist;

/**
 * TokenBlacklist with the screening policy: an RPC that cannot answer follows
 * X402_SCREENING_UNAVAILABLE ("allow" passes, anything else rejects).
 */
final class BlacklistFactory
{
    public static function create(ClientInterface $http, RequestFactoryInterface $requests, StreamFactoryInterface $streams, string $unavailable): TokenBlacklist
    {
        return new TokenBlacklist($http, $requests, $streams, 'allow' === $unavailable);
    }
}
