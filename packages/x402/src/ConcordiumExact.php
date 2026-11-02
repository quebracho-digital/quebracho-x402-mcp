<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The `exact` scheme on Concordium (x402-foundation specs/schemes/exact/
 * scheme_exact_concordium.md): a V1 sponsored CCD transfer, signed by the
 * buyer, its sponsor slot empty for the facilitator.
 *
 * Offline rules only (the spec's checks 1 to 7 and 9). The sender's signature
 * against its on-chain keys, the nonce and the balance are the facilitator's.
 *
 * ponytail: native CCD only. A PLT (tokenUpdate, CBOR operations) is refused as
 * unsupported_asset; add it when a PLT is the price.
 */
final class ConcordiumExact implements Scheme
{
    /**
     * @param ?array{address: string, signKey: string} $buyer
     *                                                         The paying account and its Ed25519 seed (hex); a seller that only verifies passes none
     * @param ?\Closure                                $nonceOf
     *                                                         fn(string $address): int, the account's next sequence number from a node; needed to pay
     * @param ?\Closure                                $clock
     *                                                         Returns the current Unix time; tests pin it
     */
    public function __construct(
        private readonly ?array $buyer = null,
        private readonly ?\Closure $nonceOf = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function supports(string $network): bool
    {
        return str_starts_with($network, 'ccd:');
    }

    public function pay(array $required, array $req): array
    {
        $buyer = $this->buyer ?? throw new \LogicException('Paying needs a buyer account.');
        $nonceOf = $this->nonceOf ?? throw new \LogicException('Paying needs a way to read the nonce.');
        $tx = [
            'version' => 1,
            'header' => [
                'sender' => $buyer['address'],
                'nonce' => $nonceOf($buyer['address']),
                'expiry' => $this->now() + (int) $req['maxTimeoutSeconds'],
                'executionEnergyAmount' => Concordium::TRANSFER_ENERGY,
                'numSignatures' => 1,
                'sponsor' => ['account' => $req['extra']['feePayer'], 'numSignatures' => 1],
            ],
            'payload' => ['type' => 'transfer', 'toAddress' => $req['payTo'], 'amount' => (string) $req['amount']],
            'signatures' => ['sender' => [], 'sponsor' => []],
        ];
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin($buyer['signKey'])));
        $tx['signatures']['sender'] = ['0' => ['0' => bin2hex(sodium_crypto_sign_detached(Concordium::signHash($tx), $secret))]];

        return [
            'x402Version' => 2,
            'resource' => $required['resource'] ?? null,
            'accepted' => $req,
            'payload' => ['signedTransaction' => $tx],
        ];
    }

    public function verify(array $payment, array $req): ?string
    {
        $tx = $payment['payload']['signedTransaction'] ?? null;
        $accepted = $payment['accepted'] ?? null;
        if (!is_array($tx) || !is_array($accepted)) {
            return 'invalid_payload';
        }
        $h = $tx['header'] ?? null;
        $p = $tx['payload'] ?? null;
        $sig = $tx['signatures']['sender']['0']['0'] ?? null;
        $feePayer = $req['extra']['feePayer'] ?? null;
        if (!is_array($h) || !is_array($p) || !is_string($sig) || !is_string($feePayer)) {
            return 'invalid_payload';
        }
        $now = $this->now();
        $sponsor = $h['sponsor']['account'] ?? $h['sponsor']['address'] ?? null;
        // Strings and ints both appear in SDK JSON; the digest and these checks read the same text.
        $same = static fn (mixed $a, mixed $b): bool => (is_string($a) || is_int($a)) && (string) $a === (string) $b;

        // Reason codes: the x402 v2 spec's §9 where it has one.
        return match (true) {
            ($payment['x402Version'] ?? null) !== 2 => 'invalid_x402_version',
            ($accepted['scheme'] ?? null) !== 'exact' => 'invalid_scheme',
            ($accepted['network'] ?? null) !== $req['network'] => 'invalid_network',
            !preg_match('/^[1-9][0-9]*$/', (string) ($req['amount'] ?? '')) => 'invalid_payment_requirements',
            'CCD' !== ($req['asset'] ?? null) => 'unsupported_asset',
            ($accepted['asset'] ?? null) !== $req['asset'] || ($accepted['payTo'] ?? null) !== $req['payTo'] || !$same($accepted['amount'] ?? null, $req['amount']) => 'invalid_payload',
            1 !== ($tx['version'] ?? null) => 'invalid_exact_concordium_version',
            'transfer' !== ($p['type'] ?? null) || array_diff(array_keys($p), ['type', 'toAddress', 'amount']) => 'invalid_exact_concordium_payload_type',
            $sponsor !== $feePayer => 'invalid_exact_concordium_payload_sponsor_mismatch',
            ($p['toAddress'] ?? null) !== $req['payTo'] => 'invalid_exact_concordium_payload_recipient_mismatch',
            !$same($p['amount'] ?? null, $req['amount']) => 'invalid_exact_concordium_payload_amount_mismatch',
            // The sponsor pays the fee and cannot be a party: no draining it by paying itself.
            ($h['sender'] ?? null) === $feePayer || $req['payTo'] === $feePayer => 'invalid_exact_concordium_payload_sponsor_is_party',
            // What the sponsor will pay for is bounded here: a plain transfer, one signature each.
            ($h['executionEnergyAmount'] ?? null) !== Concordium::TRANSFER_ENERGY
              || 1 !== ($h['numSignatures'] ?? null)
              || 1 !== ($h['sponsor']['numSignatures'] ?? null)
              || $tx['signatures']['sponsor'] ?? [] => 'invalid_exact_concordium_payload_energy',
            !is_int($h['expiry'] ?? null) || $h['expiry'] < $now + 3 => 'invalid_exact_concordium_payload_expiry',
            $h['expiry'] > $now + (int) ($req['maxTimeoutSeconds'] ?? 0) => 'invalid_exact_concordium_payload_expiry',
            !preg_match('/^[0-9a-f]{128}$/', $sig) => 'invalid_exact_concordium_payload_signature',
            default => $this->wellFormed($tx),
        };
    }

    public function payer(array $payment): ?string
    {
        $sender = $payment['payload']['signedTransaction']['header']['sender'] ?? null;

        return is_string($sender) ? $sender : null;
    }

    /**
     * Addresses and numbers that the digest can read; NULL when they are.
     */
    private function wellFormed(array $tx): ?string
    {
        try {
            Concordium::signHash($tx);

            return null;
        } catch (InvalidPaymentException) {
            return 'invalid_payload';
        }
    }

    private function now(): int
    {
        return $this->clock ? ($this->clock)() : time();
    }
}
