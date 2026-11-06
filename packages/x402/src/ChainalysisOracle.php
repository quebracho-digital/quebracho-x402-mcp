<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Chainalysis' sanctions oracle, an AddressCheck listener: `isSanctioned(address)` on a contract Chainalysis
 * keeps on Ethereum and other EVM chains, read with one free eth_call, no key. Opt-in, a second opinion
 * next to OFAC's own list (OfacSdn), never instead of it: the oracle is Chainalysis' copy of that list, and
 * may lag it either way.
 *
 * EVM addresses only (the same key holds them on every EVM network, so one chain's oracle answers for all);
 * any other address is left to the other lists. The lookup is deferred: the seller reads it just before
 * settling, the buyer before signing.
 *
 * A node that cannot answer rejects (`address_check_failed`), unless $failOpen.
 */
final class ChainalysisOracle
{
    /** The oracle's address, the same on Ethereum, Polygon, Arbitrum, Optimism and Avalanche (not on Base). */
    public const string CONTRACT = '0x40C57923924B5c5c5455c48D93317139ADDaC8fb';
    /** isSanctioned(address) */
    private const string SELECTOR = '0xdf592f7d';

    /**
     * @param string $rpc      a JSON-RPC endpoint of a chain the oracle is on (Ethereum's by default:
     *                         Networks::get('eip155:1')['rpc'])
     * @param bool   $failOpen a node that cannot answer passes the address instead of rejecting it
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly ?string $rpc = null,
        private readonly bool $failOpen = false,
    ) {
    }

    public function __invoke(AddressCheck $check): void
    {
        if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $check->address)) {
            return;
        }
        $address = $check->address;
        $check->defer(fn (): ?string => $this->sanctioned($address) ? OfacSdn::REASON : null);
    }

    /**
     * @throws \Throwable when the node cannot answer and the oracle does not fail open
     */
    public function sanctioned(string $address): bool
    {
        $rpc = $this->rpc ?? (string) (Networks::get('eip155:1')['rpc'] ?? '');
        try {
            if ('' === $rpc) {
                throw new \RuntimeException('ChainalysisOracle: no RPC endpoint.');
            }
            $answer = (new Rpc($this->http, $this->requests, $this->streams, $rpc))('eth_call', [['to' => self::CONTRACT, 'data' => self::SELECTOR.Rpc::word($address)], 'latest']);
            if (!\is_string($answer) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $answer)) {
                throw new \UnexpectedValueException('isSanctioned: not a bool');
            }
        } catch (\Throwable $e) {
            if ($this->failOpen) {
                return false;
            }
            throw $e;
        }

        return 0 !== gmp_cmp(Rpc::number($answer), 0);
    }
}
