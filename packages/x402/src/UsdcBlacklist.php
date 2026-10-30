<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * An AddressCheck listener: is this address frozen in the token's own contract?
 *
 * Circle's USDC keeps a blacklist (`isBlacklisted(address)`) of addresses it
 * froze, OFAC or not. A frozen payer or payTo makes `transferWithAuthorization`
 * revert at settle, so the seller would run the tool for nothing and the buyer
 * would sign for nothing. This asks the contract first, through the network's
 * public RPC (`Networks`), about the requirement's own asset.
 *
 * Only EVM networks with an RPC: Solana and NEAR pass untouched (their freeze
 * state lives elsewhere). A node that cannot answer, or a token without
 * `isBlacklisted`, throws, which AddressCheck turns into a rejection; with
 * $failOpen it passes instead.
 *
 * ponytail: one blocking eth_call per check, no cache; add one if the RPC's latency shows.
 */
final class UsdcBlacklist
{
    private const SELECTOR = '0xfe575a87'; // isBlacklisted(address)

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly bool $failOpen = false,
    ) {
    }

    public function __invoke(AddressCheck $check): void
    {
        if (!str_starts_with($check->network, 'eip155:')) {
            return;
        }
        $rpc = Networks::get($check->network)['rpc'] ?? '';
        if ('' === $rpc) {
            return;
        }
        $asset = (string) ($check->req['asset'] ?? '');
        if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $check->address) || !preg_match('/^0x[0-9a-fA-F]{40}$/', $asset)) {
            $check->reject('invalid_payload');

            return;
        }
        try {
            $answer = (new Rpc($this->http, $this->requests, $this->streams, $rpc))('eth_call', [['to' => $asset, 'data' => self::SELECTOR.Rpc::word($check->address)], 'latest']);
            if (!\is_string($answer) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $answer)) {
                throw new \UnexpectedValueException('isBlacklisted: not a bool');
            }
        } catch (\Throwable $e) {
            if ($this->failOpen) {
                return;
            }
            throw $e;
        }
        if (0 !== gmp_cmp(Rpc::number($answer), 0)) {
            $check->reject('address_blacklisted');
        }
    }
}
