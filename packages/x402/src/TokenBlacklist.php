<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * An AddressCheck listener: is this address frozen in the token's own contract?
 *
 * Circle (USDC) and Tether (USDT) freeze addresses in the token contract, OFAC
 * or not. A frozen payer or payTo makes `transferWithAuthorization` revert at
 * settle, so the seller would run the tool for nothing and the buyer would
 * sign for nothing. This asks the contract first, through the network's public
 * RPC (`Networks`), about the requirement's own asset.
 *
 * Each token names its own function, and none is assumed: TOKENS lists the
 * ones checked on chain, `network|asset` => function. **An asset not in the
 * list is not asked** (it passes), so add yours with `$tokens`. Only EVM
 * networks; Solana and NEAR pass untouched (their freeze state lives elsewhere).
 *
 * A node that cannot answer, or a token that lacks the function, throws, which
 * AddressCheck turns into a rejection; with $failOpen it passes instead.
 *
 * ponytail: one blocking eth_call per check, no cache; add one if the RPC's latency shows.
 */
final class TokenBlacklist
{
    /**
     * Selector of each function, all `(address) returns (bool)`.
     */
    public const array FUNCTIONS = [
        'isBlacklisted' => '0xfe575a87', // Circle's USDC
        'isBlackListed' => '0xe47d6060', // Tether's USDT, legacy
        'getBlackListStatus' => '0x59bf1abe', // Tether's USDT, legacy (same answer)
        'isBlocked' => '0xfbac3951', // Tether's USDT0; no token uses it by default, see TOKENS
    ];

    /**
     * Verified with a real eth_call (the function answers, a made-up selector
     * reverts). Lower-case asset. Tether's USDT on Ethereum answered `true` for
     * 8 of 8 OFAC-listed addresses; it has no EIP-3009, so it is no payment
     * asset yet, but it is ready for a chain or token that brings USDT.
     * Not reached from here when tried: Ethereum Sepolia, Polygon Amoy (Circle's USDC, assumed).
     *
     * NOT active: USDT0 (Arbitrum, Rootstock) exposes `isBlocked(address)`
     * (0xfbac3951; the Circle and legacy Tether names revert there), but it
     * answered `false` for the same 8 addresses, so it was never seen to
     * freeze anyone. Add it through $tokens once that is confirmed.
     */
    public const array TOKENS = [
        'eip155:84532|0x036cbd53842c5426634e7929541ec2318f3dcf7e' => 'isBlacklisted',
        'eip155:5042002|0x3600000000000000000000000000000000000000' => 'isBlacklisted',
        'eip155:43113|0x5425890298aed601595a70ab815c96711a31bc65' => 'isBlacklisted',
        'eip155:11155111|0x1c7d4b196cb0c7b01d743fbc6116a902379c7238' => 'isBlacklisted',
        'eip155:80002|0x41e94eb019c0762f9bfcf9fb1e58725bfb0e7582' => 'isBlacklisted',
        'eip155:1|0xdac17f958d2ee523a2206206994597c13d831ec7' => 'isBlackListed',
    ];

    /**
     * @var array<string, string>
     */
    private readonly array $tokens;

    /**
     * @param array<string, string> $tokens
     *                                      More `network|asset` => function (a key of FUNCTIONS); the asset in any case
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly bool $failOpen = false,
        array $tokens = [],
    ) {
        $own = [];
        foreach ($tokens as $key => $function) {
            if (!isset(self::FUNCTIONS[$function])) {
                throw new \InvalidArgumentException(\sprintf('Unknown blacklist function "%s": use %s.', $function, implode(', ', array_keys(self::FUNCTIONS))));
            }
            $own[strtolower($key)] = $function;
        }
        $this->tokens = $own + self::TOKENS;
    }

    public function __invoke(AddressCheck $check): void
    {
        $asset = (string) ($check->req['asset'] ?? '');
        $function = $this->tokens[strtolower($check->network.'|'.$asset)] ?? null;
        if (null === $function || !str_starts_with($check->network, 'eip155:')) {
            return;
        }
        if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $check->address)) {
            $check->reject('invalid_payload');

            return;
        }
        $rpc = Networks::get($check->network)['rpc'] ?? '';
        if ('' === $rpc) {
            // ponytail: a token we know but a network without a node: nothing to ask.
            return;
        }
        try {
            $answer = (new Rpc($this->http, $this->requests, $this->streams, $rpc))('eth_call', [['to' => $asset, 'data' => self::FUNCTIONS[$function].Rpc::word($check->address)], 'latest']);
            if (!\is_string($answer) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $answer)) {
                throw new \UnexpectedValueException("$function: not a bool");
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
