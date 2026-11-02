<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Concordium's wire bits that a payment needs, offline: account addresses and
 * the digest a V1 sponsored transfer is signed over.
 *
 * Ported from the Kalibang spike, whose digest the chain finalized on testnet
 * (2026-10-21). The node's own sign-hash call is unimplemented on public
 * nodes, so the digest is ours, as in the JS SDK's AccountTransactionV1.
 */
final class Concordium
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /** Energy of a CCD transfer, before signatures and size (the JSON's executionEnergyAmount). */
    public const TRANSFER_ENERGY = 300;

    /**
     * An account address (base58check, version byte 1) as its 32 raw bytes.
     *
     * @throws InvalidPaymentException
     */
    public static function decodeAddress(string $address): string
    {
        $n = gmp_init(0);
        foreach (str_split($address) as $char) {
            $digit = strpos(self::ALPHABET, $char);
            if (false === $digit) {
                throw new InvalidPaymentException("Not base58: $address");
            }
            $n = gmp_add(gmp_mul($n, 58), $digit);
        }
        $bytes = str_pad(gmp_export($n), 37, "\0", \STR_PAD_LEFT);
        if (37 !== strlen($bytes) || "\x01" !== $bytes[0] || substr(hash('sha256', hash('sha256', substr($bytes, 0, 33), true), true), 0, 4) !== substr($bytes, 33)) {
            throw new InvalidPaymentException("Not a Concordium account address: $address");
        }

        return substr($bytes, 1, 32);
    }

    public static function encodeAddress(string $raw): string
    {
        $payload = "\x01".$raw;
        $n = gmp_import($payload.substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4));
        $out = '';
        while (gmp_sign($n) > 0) {
            [$n, $r] = gmp_div_qr($n, 58);
            $out = self::ALPHABET[gmp_intval($r)].$out;
        }

        return $out;
    }

    /**
     * Energy the whole transaction costs: the base, 100 per signature and one
     * per byte of header and payload. The sponsor pays for all of it.
     */
    public static function energy(int $base, int $signatures, int $headerAndPayloadBytes): int
    {
        return $base + 100 * $signatures + $headerAndPayloadBytes;
    }

    /**
     * The digest sender and sponsor both sign, of an SDK-JSON V1 transfer.
     *
     * @param array $tx
     *                  `signedTransaction` of the payload; only the fields below are read
     *
     * @throws InvalidPaymentException
     *                                 Not a CCD transfer, or a field that is not what it should be
     */
    public static function signHash(array $tx): string
    {
        $h = $tx['header'] ?? [];
        $p = $tx['payload'] ?? [];
        if (!is_array($h) || !is_array($p) || 'transfer' !== ($p['type'] ?? null)) {
            throw new InvalidPaymentException('Only a plain CCD transfer is supported.');
        }
        $sender = self::decodeAddress((string) ($h['sender'] ?? ''));
        $to = self::decodeAddress((string) ($p['toAddress'] ?? ''));
        $sponsor = self::decodeAddress((string) ($h['sponsor']['account'] ?? $h['sponsor']['address'] ?? ''));
        $signatures = (int) ($h['numSignatures'] ?? 0) + (int) ($h['sponsor']['numSignatures'] ?? 0);
        foreach (['nonce' => $h['nonce'] ?? null, 'expiry' => $h['expiry'] ?? null, 'amount' => $p['amount'] ?? null] as $name => $v) {
            if (!(is_int($v) && $v >= 0) && !(is_string($v) && preg_match('/^(0|[1-9]\d{0,19})$/', $v))) {
                throw new InvalidPaymentException("The $name is not a plain integer.");
            }
        }
        $u64 = static fn (int|string $n): string => str_pad(gmp_export(gmp_init((string) $n, 10)), 8, "\0", \STR_PAD_LEFT);
        $body = "\x03".$to.$u64($p['amount']);
        // Header: bitmap u16 (bit 0 = sponsored), sender, nonce, energy, payload size u32, expiry, sponsor.
        $energy = self::energy((int) ($h['executionEnergyAmount'] ?? 0), $signatures, 2 + 32 + 8 + 8 + 4 + 8 + 32 + strlen($body));
        $header = pack('n', 1).$sender.$u64($h['nonce']).$u64($energy).pack('N', strlen($body)).$u64($h['expiry']).$sponsor;

        return hash('sha256', str_repeat("\0", 31)."\x01".$header.$body, true);
    }

    /**
     * Whether an Ed25519 signature (hex) over the transaction's digest was made by $publicKey (hex).
     */
    public static function signedBy(array $tx, string $signatureHex, string $publicKeyHex): bool
    {
        if (!preg_match('/^[0-9a-f]{128}$/', $signatureHex) || !preg_match('/^[0-9a-f]{64}$/', $publicKeyHex)) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached(hex2bin($signatureHex), self::signHash($tx), hex2bin($publicKeyHex));
        } catch (InvalidPaymentException) {
            return false;
        }
    }
}
