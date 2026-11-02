<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\Concordium;
use Quebracho\X402\ConcordiumExact;

final class ConcordiumExactTest extends TestCase
{
    private const NOW = 1700000000;
    private const SENDER = '35G83kEaPTNk9DkdkbXtGyRiBvZpxAbqgYSzdLMfJLkKwHU7TE';
    private const PAY_TO = '3Cn4s3SJMEXMTroMva4pEM5WGER1CVKtj1nk6ypKwg7AJV6JqA';
    private const SPONSOR = '3LJ1gLe2K1fxnVr66YbkBijJLYGBSp3wmV8VadGzb1TzcXNtAV';
    // Throwaway seed: these tests never touch a real key.
    private const SEED = '0101010101010101010101010101010101010101010101010101010101010101';

    private function req(): array
    {
        return [
            'scheme' => 'exact', 'network' => 'ccd:4221332d34e1694168c2a0c0b3fd0f27', 'amount' => '1000000', 'asset' => 'CCD',
            'payTo' => self::PAY_TO, 'maxTimeoutSeconds' => 60, 'extra' => ['feePayer' => self::SPONSOR],
        ];
    }

    private function scheme(): ConcordiumExact
    {
        return new ConcordiumExact(['address' => self::SENDER, 'signKey' => self::SEED], fn () => 42, fn () => self::NOW);
    }

    private function payment(): array
    {
        return $this->scheme()->pay([], $this->req());
    }

    public function testSignHashMatchesTheSpikeWhoseTransactionFinalized(): void
    {
        // Same inputs through the spike's formula (kalibang/spike/step2-send.php): 635 NRG, as on testnet.
        $tx = $this->payment()['payload']['signedTransaction'];
        $tx['header']['expiry'] = 1700000300;
        $this->assertSame('458d433db94f3007c7e31a63f1e1f092ddf3eb438489536d0a3ec5f92eb815b8', bin2hex(Concordium::signHash($tx)));
    }

    public function testAddressRoundTrip(): void
    {
        $this->assertSame(str_repeat("\x11", 32), Concordium::decodeAddress(self::SENDER));
        $this->assertSame(self::SPONSOR, Concordium::encodeAddress(str_repeat("\x33", 32)));
    }

    public function testAHonestPaymentVerifiesAndIsSignedByTheBuyer(): void
    {
        $payment = $this->payment();
        $this->assertNull($this->scheme()->verify($payment, $this->req()));
        $this->assertSame(self::SENDER, $this->scheme()->payer($payment));
        $tx = $payment['payload']['signedTransaction'];
        $public = bin2hex(sodium_crypto_sign_publickey_from_secretkey(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin(self::SEED)))));
        $this->assertTrue(Concordium::signedBy($tx, $tx['signatures']['sender']['0']['0'], $public));
        $tx['payload']['amount'] = '999999';
        $this->assertFalse(Concordium::signedBy($tx, $tx['signatures']['sender']['0']['0'], $public));
    }

    #[DataProvider('tampered')]
    public function testRefusesWhatTheSponsorMustNotPayFor(callable $tamper, string $reason): void
    {
        $payment = $this->payment();
        $req = $this->req();
        $tamper($payment, $req);
        $this->assertSame($reason, $this->scheme()->verify($payment, $req));
    }

    public static function tampered(): iterable
    {
        yield 'other sponsor' => [function (&$p) { $p['payload']['signedTransaction']['header']['sponsor']['account'] = self::PAY_TO; }, 'invalid_exact_concordium_payload_sponsor_mismatch'];
        yield 'other recipient' => [function (&$p) { $p['payload']['signedTransaction']['payload']['toAddress'] = self::SENDER; }, 'invalid_exact_concordium_payload_recipient_mismatch'];
        yield 'less' => [function (&$p) { $p['payload']['signedTransaction']['payload']['amount'] = '999999'; }, 'invalid_exact_concordium_payload_amount_mismatch'];
        yield 'more' => [function (&$p) { $p['payload']['signedTransaction']['payload']['amount'] = '1000001'; }, 'invalid_exact_concordium_payload_amount_mismatch'];
        yield 'token update' => [function (&$p) { $p['payload']['signedTransaction']['payload']['type'] = 'tokenUpdate'; }, 'invalid_exact_concordium_payload_type'];
        yield 'extra operation' => [function (&$p) { $p['payload']['signedTransaction']['payload']['operations'] = '00'; }, 'invalid_exact_concordium_payload_type'];
        yield 'version 0' => [function (&$p) { $p['payload']['signedTransaction']['version'] = 0; }, 'invalid_exact_concordium_version'];
        yield 'sender is the sponsor' => [function (&$p) { $p['payload']['signedTransaction']['header']['sender'] = self::SPONSOR; }, 'invalid_exact_concordium_payload_sponsor_is_party'];
        yield 'pays the sponsor' => [function (&$p, &$r) { $r['payTo'] = self::SPONSOR; $p['accepted']['payTo'] = self::SPONSOR; $p['payload']['signedTransaction']['payload']['toAddress'] = self::SPONSOR; }, 'invalid_exact_concordium_payload_sponsor_is_party'];
        yield 'fat energy' => [function (&$p) { $p['payload']['signedTransaction']['header']['executionEnergyAmount'] = 1000000; }, 'invalid_exact_concordium_payload_energy'];
        yield 'sponsor slot filled' => [function (&$p) { $p['payload']['signedTransaction']['signatures']['sponsor'] = ['0' => ['0' => str_repeat('a', 128)]]; }, 'invalid_exact_concordium_payload_energy'];
        yield 'expired' => [function (&$p) { $p['payload']['signedTransaction']['header']['expiry'] = self::NOW + 1; }, 'invalid_exact_concordium_payload_expiry'];
        yield 'too far' => [function (&$p) { $p['payload']['signedTransaction']['header']['expiry'] = self::NOW + 61; }, 'invalid_exact_concordium_payload_expiry'];
        yield 'no signature' => [function (&$p) { $p['payload']['signedTransaction']['signatures']['sender'] = []; }, 'invalid_payload'];
        yield 'short signature' => [function (&$p) { $p['payload']['signedTransaction']['signatures']['sender']['0']['0'] = 'ab'; }, 'invalid_exact_concordium_payload_signature'];
        yield 'bad address' => [function (&$p) { $p['payload']['signedTransaction']['header']['sender'] = 'nope'; }, 'invalid_payload'];
        yield 'wrong network' => [function (&$p) { $p['accepted']['network'] = 'ccd:9dd9ca4d19e9393877d2c44b70f89acb'; }, 'invalid_network'];
        yield 'PLT asked' => [function (&$p, &$r) { $r['asset'] = 'EURR'; }, 'unsupported_asset'];
    }

    public function testSupportsOnlyConcordium(): void
    {
        $this->assertTrue($this->scheme()->supports('ccd:4221332d34e1694168c2a0c0b3fd0f27'));
        $this->assertFalse($this->scheme()->supports('eip155:84532'));
    }
}
