<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Whether an EIP-3009 authorization was used on chain: the token's authorizationState(from, nonce), read through
 * the network's RPC (Networks). Budget's $used: give it as `new Budget(..., used: new AuthorizationState(...))`,
 * and an expired signature the seller never settled is given back to the budget.
 *
 * Throws when the node cannot answer: Budget keeps the amount spent and asks again next time.
 */
final class AuthorizationState
{
    /** authorizationState(address,bytes32), EIP-3009. */
    public const string SELECTOR = '0xe94a0102';

    /**
     * @param ClientInterface $http give it a short timeout (5 s): reclaim() runs before each payment
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when $from or $nonce is not EIP-3009's
     * @throws \RuntimeException         when the node cannot answer, or answers something else than a bool
     */
    public function __invoke(string $network, string $from, string $nonce): bool
    {
        if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $from) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $nonce)) {
            throw new \InvalidArgumentException('Not an EIP-3009 from/nonce.');
        }
        $token = Networks::get($network);
        $result = (new Rpc($this->http, $this->requests, $this->streams, $token['rpc']))('eth_call', [['to' => $token['asset'], 'data' => self::SELECTOR.Rpc::word($from).substr($nonce, 2)], 'latest']);
        if (!\is_string($result) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $result)) {
            throw new \UnexpectedValueException('authorizationState did not answer a bool word.');
        }

        return 0 !== gmp_cmp(Rpc::number($result), 0);
    }
}
